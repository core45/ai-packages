<?php

declare(strict_types=1);

namespace Core45\AiChat\Http\Controllers;

use Core45\AiCore\DTOs\ChatRequest;
use Core45\AiCore\DTOs\StreamEvent;
use Core45\AiCore\Enums\AIMessageStatus;
use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Models\AIConversationMessage;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\RemoteAiClient;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Host-proxied SSE endpoint for the `chat` and `customer_chat` AI surfaces (CUT-02).
 *
 * Design (a) — host-proxied SSE, Reverb-free (Open Q2). The browser `EventSource`
 * opens a session-authenticated GET endpoint here; this controller consumes
 * phasea's upstream SSE via `RemoteAiClient` and DIRECTLY EMITS the host's
 * stable event schema (chunk/tool_start/tool_complete/error/done/heartbeat,
 * plus the Phase 31 additions `injection` (C31-2) and `action_proposal`
 * (C31-4) — both synthesized inside `RemoteAiClient::synthesizeEvents()` and
 * re-emitted verbatim here) to the browser. No Reverb broadcast is involved,
 * which preserves the host-owned event schema AND permits `laravel/reverb`
 * removal later (CUT-07, 10-16).
 *
 * Guest chat (customer_chat, anonymous audience) is re-authorized session-based
 * (Open Q5): the guest SSE endpoint binds to the session's `ai_conversation_id`
 * and confirms the conversation is a guest conversation (`whereNull('user_id')`),
 * reproducing `GuestBroadcastController`'s isolation guarantee WITHOUT the Reverb
 * HMAC. The controller brokers a short-lived, tenant+conversation scoped identity
 * to phasea (a guest-scoped `customer_id`) so the upstream customer stream can be
 * reached; cross-guest leakage is impossible because authorization happens here,
 * before any bytes are proxied.
 *
 * Remote-only routing (D17, CONTRACT-05): `AiClientFactory` unconditionally
 * resolves `RemoteAiClient` for every non-D11 surface, so this proxy always
 * streams — there is no config-driven gate that can 409 it.
 */
class ChatStreamProxyController
{
    /**
     * SSE headers for the host->browser stream (mirrors phasea ChatController).
     *
     * @var array<string, string>
     */
    private const SSE_HEADERS = [
        'Content-Type' => 'text/event-stream',
        'Cache-Control' => 'no-cache',
        'X-Accel-Buffering' => 'no',
        'Connection' => 'keep-alive',
    ];

    public function __construct(
        private readonly RemoteAiClient $client,
    ) {}

    /**
     * Admin chat SSE stream.
     *
     * GET /ai/chat/stream?conversation_id={id}&message_id={id}&operation_id={uuid}  (message={text} is the legacy fallback)
     */
    public function adminStream(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'message_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:10000'],
            'operation_id' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user === null) {
            abort(403, 'Authentication required.');
        }

        if (!Str::isUuid($validated['operation_id'])) {
            abort(422, 'operation_id must be a valid UUID.');
        }

        // Ownership: the conversation must belong to the authenticated admin.
        // Fetched (not exists()) so its stored record context (model_type/
        // model_id) can ride the upstream request (C31-1).
        $conversation = AIConversation::where('id', $validated['conversation_id'])
            ->where('user_id', $user->id)
            ->where('audience', 'admin')
            ->first();

        if ($conversation === null) {
            abort(403, 'You do not have access to this conversation.');
        }

        [$outboundMessage, $assistantMessage, $userMessage] = $this->resolveOutboundMessage(
            (int) $validated['conversation_id'],
            (int) $validated['message_id'],
            $validated['message'] ?? null,
        );

        // C31-1 (plan 48-20): the conversation row stores the panel's model
        // context, and the USER MESSAGE row stores the field reference and record
        // snapshot the panel held when it was sent. Before 48-20 those facts
        // reached phasea only as a `[FIELD REFERENCE]` prose block the host wrote
        // into the message body — this path forwarded the enriched text because it
        // forwarded nothing else. Reading them off `metadata` is what let that
        // host-authored block be deleted. RemoteAiClient maps the host model class
        // onto the neutral slug phasea expects.
        $outboundContext = $this->outboundContextFrom($userMessage);

        return $this->proxyStream(
            $this->client->streamChat(new ChatRequest(
                message: $outboundMessage,
                conversationId: (string) $validated['conversation_id'],
                modelType: $conversation->model_type,
                modelId: $conversation->model_id !== null ? (int) $conversation->model_id : null,
                operationId: $validated['operation_id'],
                recordContext: $outboundContext['record_context'],
                fieldReference: $outboundContext['field_reference'],
            )),
            $assistantMessage,
        );
    }

    /**
     * Read the persisted C31-1 context off a user message row.
     *
     * Everything here is host-written data (the panel's own form state), but it
     * is read back out of a JSON column, so each entry is shape-checked rather
     * than trusted — a hand-edited row must degrade to "no context", never to a
     * malformed outbound payload.
     *
     * @return array{record_context: array<string, mixed>|null, field_reference: string|null}
     */
    private function outboundContextFrom(?AIConversationMessage $userMessage): array
    {
        $metadata = $userMessage?->metadata;

        if (!is_array($metadata)) {
            return ['record_context' => null, 'field_reference' => null];
        }

        $fieldReference = $metadata['field_reference'] ?? null;
        $recordContext = $metadata['record_context'] ?? null;

        return [
            'record_context' => is_array($recordContext) && $recordContext !== [] ? $recordContext : null,
            'field_reference' => is_string($fieldReference) && $fieldReference !== '' ? $fieldReference : null,
        ];
    }

    /**
     * Authenticated customer chat SSE stream.
     *
     * GET /ai/customer-chat/stream?conversation_id={id}&message_id={id}&operation_id={uuid}  (message={text} is the legacy fallback)
     */
    public function customerStream(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'message_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:5000'],
            'operation_id' => ['required', 'string'],
        ]);

        $user = $request->user();

        if ($user === null) {
            abort(403, 'Authentication required.');
        }

        if (!Str::isUuid($validated['operation_id'])) {
            abort(422, 'operation_id must be a valid UUID.');
        }

        // Ownership: a customer conversation owned by this user.
        $owns = AIConversation::where('id', $validated['conversation_id'])
            ->where('user_id', $user->id)
            ->where('audience', 'customer')
            ->exists();

        if (!$owns) {
            abort(403, 'You do not have access to this conversation.');
        }

        $customerId = $this->resolvePhaseaCustomerId($user);

        [$outboundMessage, $assistantMessage] = $this->resolveOutboundMessage(
            (int) $validated['conversation_id'],
            (int) $validated['message_id'],
            $validated['message'] ?? null,
        );

        return $this->proxyStream(
            $this->client->streamCustomerChat(
                message: $outboundMessage,
                conversationId: (string) $validated['conversation_id'],
                customerId: $customerId,
                operationId: $validated['operation_id'],
            ),
            $assistantMessage,
        );
    }

    /**
     * Anonymous guest chat SSE stream (customer_chat, audience=anonymous).
     *
     * Reproduces `GuestBroadcastController`'s session-scoped + `whereNull('user_id')`
     * isolation guarantee on the SSE transport (CUT-03, Open Q5). No Reverb HMAC.
     *
     * GET /ai/guest-chat/stream?conversation_id={id}&message_id={id}&operation_id={uuid}  (message={text} is the legacy fallback)
     */
    public function guestStream(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'message_id' => ['required', 'integer'],
            'message' => ['nullable', 'string', 'max:5000'],
            'operation_id' => ['required', 'string'],
        ]);

        if (!Str::isUuid($validated['operation_id'])) {
            abort(422, 'operation_id must be a valid UUID.');
        }

        $conversationId = $validated['conversation_id'];

        // Session-bound: the guest may only stream the conversation their session owns.
        $sessionConversationId = $request->session()->get('ai_conversation_id');

        if ((string) $sessionConversationId !== (string) $conversationId) {
            abort(403, 'This conversation is not bound to your session.');
        }

        // Guest isolation: the conversation must be a guest conversation (no user).
        // Reproduces GuestBroadcastController's whereNull('user_id') check (T-10-14-01).
        $isGuestConversation = AIConversation::where('id', $conversationId)
            ->whereNull('user_id')
            ->exists();

        if (!$isGuestConversation) {
            abort(404, 'Conversation not found.');
        }

        // Broker a short-lived, tenant+conversation scoped identity to phasea so the
        // upstream customer stream can be reached. Guests are scoped to their session,
        // never to another guest's conversation (enforced above).
        $sessionId = (string) $request->session()->getId();
        $guestCustomerId = 'guest:'.hash('sha256', $sessionId.':'.$conversationId);

        [$outboundMessage, $assistantMessage] = $this->resolveOutboundMessage(
            (int) $conversationId,
            (int) $validated['message_id'],
            $validated['message'] ?? null,
        );

        return $this->proxyStream(
            $this->client->streamCustomerChat(
                message: $outboundMessage,
                conversationId: (string) $conversationId,
                customerId: $guestCustomerId,
                operationId: $validated['operation_id'],
                guest: true,
            ),
            $assistantMessage,
        );
    }

    /**
     * Emit the host's 6-event schema to the browser `EventSource`.
     *
     * @param  \Generator<int, StreamEvent, mixed, void>  $events
     */
    private function proxyStream(\Generator $events, AIConversationMessage $assistantMessage): StreamedResponse
    {
        return response()->stream(function () use ($events, $assistantMessage): void {
            ignore_user_abort(true);

            $content = '';
            $completed = false;
            $failed = false;

            $assistantMessage->update(['status' => AIMessageStatus::Streaming]);

            try {
                foreach ($events as $event) {
                    if (connection_aborted()) {
                        $failed = true;
                        break;
                    }

                    if ($event->type === 'chunk') {
                        $chunk = $event->payload['text'] ?? '';
                        $content .= is_string($chunk) ? $chunk : '';
                    } elseif ($event->type === 'error') {
                        $failed = true;
                    } elseif ($event->type === 'done') {
                        $completed = true;
                    }

                    echo 'data: '.json_encode($event->toArray(), JSON_THROW_ON_ERROR)."\n\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            } catch (\Throwable) {
                $failed = true;

                // Surface a terminal error event so the frontend never hangs.
                echo 'data: '.json_encode([
                    'type' => 'error',
                    'payload' => ['message' => 'AI stream interrupted'],
                    'v' => 1,
                ], JSON_THROW_ON_ERROR)."\n\n";
            } finally {
                if ($failed || !$completed) {
                    $assistantMessage->update([
                        'content' => $content,
                        'status' => AIMessageStatus::Error,
                        'has_error' => true,
                        'error_type' => 'remote_stream',
                    ]);
                } else {
                    $assistantMessage->update([
                        'content' => $content,
                        'status' => AIMessageStatus::Complete,
                        'has_error' => false,
                        'error_type' => null,
                    ]);
                }
            }
        }, 200, self::SSE_HEADERS);
    }

    /**
     * Message-id handshake (30-B1, audit A1/A2).
     *
     * `message_id` may reference either:
     * - the stored USER message (preferred handshake): its stored body is
     *   forwarded upstream — together with the C31-1 context on its `metadata`
     *   (48-20; before that the context rode INSIDE the body as a host-built
     *   `[FIELD REFERENCE]` block) — and the pending assistant placeholder
     *   created after it receives the stream; or
     * - the pending ASSISTANT placeholder (legacy handshake): the raw
     *   `message` query param is forwarded, preserving backward compatibility
     *   with clients that re-send the typed text.
     *
     * Ownership is fail-closed: the caller's conversation is authorized
     * BEFORE this runs, and the message must belong to that conversation —
     * foreign or unknown ids 404 without leaking existence.
     *
     * The third element is the USER message row when the handshake referenced
     * one — the admin path reads its `metadata` for the C31-1 context (48-20).
     * The legacy branch (message_id pointing at the assistant placeholder) has
     * no user row and yields null, so that path simply carries no context, as it
     * did before.
     *
     * @return array{0: string, 1: AIConversationMessage, 2: AIConversationMessage|null}
     */
    private function resolveOutboundMessage(int $conversationId, int $messageId, ?string $fallbackMessage): array
    {
        $message = AIConversationMessage::query()
            ->whereKey($messageId)
            ->where('ai_conversation_id', $conversationId)
            ->first();

        if ($message === null) {
            abort(404, 'Message not found.');
        }

        if ($message->role === 'user') {
            $assistantMessage = AIConversationMessage::query()
                ->where('ai_conversation_id', $conversationId)
                ->where('role', 'assistant')
                ->where('status', AIMessageStatus::Pending)
                ->where('id', '>', $message->id)
                ->orderBy('id')
                ->first();

            if ($assistantMessage === null) {
                abort(404, 'Assistant message placeholder not found.');
            }

            $storedBody = (string) $message->content;

            if (trim($storedBody) === '') {
                abort(422, 'The referenced message has no content.');
            }

            return [$storedBody, $assistantMessage, $message];
        }

        // Legacy handshake: message_id references the assistant placeholder,
        // so the raw message text must be supplied by the client.
        if ($fallbackMessage === null || trim($fallbackMessage) === '') {
            abort(422, 'message is required when message_id does not reference a user message.');
        }

        return [$fallbackMessage, $this->assistantPlaceholder($conversationId, $messageId), null];
    }

    private function assistantPlaceholder(string|int $conversationId, string|int $messageId): AIConversationMessage
    {
        $message = AIConversationMessage::query()
            ->whereKey($messageId)
            ->where('ai_conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->where('status', AIMessageStatus::Pending)
            ->first();

        if ($message === null) {
            abort(404, 'Assistant message placeholder not found.');
        }

        return $message;
    }

    /**
     * Resolve the phasea-scoped customer id for an authenticated host user.
     *
     * The host does not yet persist a phasea customer uuid, so we fall back to a
     * stable, host-derived identifier. Single-tenant host today; this is the seam
     * where a real bridge mapping would be injected (T-10-14-02 scoping).
     */
    private function resolvePhaseaCustomerId(User $user): string
    {
        /** @var string|null $mapped */
        $mapped = $user->getAttribute('phasea_customer_id');

        return is_string($mapped) && $mapped !== '' ? $mapped : (string) $user->getAuthIdentifier();
    }
}
