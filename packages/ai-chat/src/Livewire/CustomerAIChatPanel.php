<?php

declare(strict_types=1);

namespace Core45\AiChat\Livewire;

use Core45\AiCore\Enums\AIMessageStatus;
use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Models\AIConversationMessage;
use Core45\AiChat\Contracts\ChatProductCardContract;
use Core45\AiChat\Services\CustomerAIService;
use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\DTOs\ChatRequest;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Exceptions\AiClientException;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\RemoteAiClient;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Lazy;
use Livewire\Component;
use Stevebauman\Purify\Facades\Purify;

#[Lazy]
class CustomerAIChatPanel extends Component
{
    public bool $isOpen = false;

    public string $message = '';

    /** @var array<int, array{role: string, content: string, type?: string, products?: array<int, array<string, mixed>>, feedback?: string, isStreaming?: bool, id?: int}> */
    public array $messages = [];

    public ?int $conversationId = null;

    public bool $isLoading = false;

    public ?string $error = null;

    public string $sessionId = '';

    /**
     * ID of the message currently being streamed.
     */
    public ?int $streamingMessageId = null;

    protected AiClient $client;

    /**
     * Resolve the parity-seam `AiClient` via Livewire boot() injection.
     *
     * Pure placeholder for the customer surface: every actual call
     * (`isEnabled()`, `getWelcomeMessage()`, `chat()`) stays on
     * `CustomerAIService`. Unlike the admin panel, `AiClient::isEnabled()`
     * checks ONLY the admin flag, whereas `CustomerAIService::isEnabled()`
     * additionally gates on `customer_assistant.enabled` — routing it through
     * `$client` would silently change the enabled condition. Real routing is
     * deferred to the Phase 10 cutover.
     */
    public function boot(AiClient $client): void
    {
        $this->client = $client;
    }

    public function hydrate(): void
    {
        $this->messages = array_values(array_filter($this->messages, 'is_array'));
    }

    public function mount(): void
    {
        // Generate or retrieve session ID for anonymous users
        $sessionValue = session()->get('customer_ai_session');
        $this->sessionId = is_string($sessionValue) ? $sessionValue : Str::uuid()->toString();
        session()->put('customer_ai_session', $this->sessionId);

        // Add welcome message
        $service = app(CustomerAIService::class);
        if ($service->isEnabled()) {
            $this->messages[] = [
                'role' => 'assistant',
                'content' => $service->getWelcomeMessage(),
            ];
        }
    }

    public function toggle(): void
    {
        $this->isOpen = !$this->isOpen;
    }

    public function sendMessage(): void
    {
        // Validate and sanitize input
        $this->validate([
            'message' => 'required|string|max:5000',
        ]);

        /** @var string $sanitizedMessage */
        $sanitizedMessage = Purify::clean(trim($this->message));
        if (empty($sanitizedMessage)) {
            return;
        }

        // Rate limiting: 10 messages per minute per session
        $rateLimitKey = 'customer-ai-chat:'.($this->sessionId ?: request()->ip());
        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            $this->error = __('customer_ai.rate_limit_exceeded');

            return;
        }
        RateLimiter::hit($rateLimitKey, 60);

        $this->error = null;

        // Determine audience based on authentication
        $audience = Auth::check() ? 'customer' : 'anonymous';
        $user = Auth::user();

        // Get or create conversation
        if ($this->conversationId !== null) {
            $conversation = AIConversation::find($this->conversationId);
        } else {
            $conversation = null;
        }

        if ($conversation === null) {
            $conversation = AIConversation::create([
                'user_id' => $user?->id,
                'session_id' => $audience === 'anonymous' ? $this->sessionId : null,
                'audience' => $audience,
                'locale' => app()->getLocale(),
                'status' => 'active',
            ]);
            $this->conversationId = $conversation->id;

            // Store conversation ID in session for WebSocket channel authorization (guest users)
            if ($audience === 'anonymous') {
                session(['ai_conversation_id' => $conversation->id]);
            }
        }

        // Save user message to database
        /** @var AIConversationMessage $savedUserMessage */
        $savedUserMessage = $conversation->messages()->create([
            'role' => 'user',
            'content' => $sanitizedMessage,
            'status' => AIMessageStatus::Complete,
        ]);

        // Add user message to display
        $this->messages[] = [
            'role' => 'user',
            'content' => $sanitizedMessage,
            'id' => $savedUserMessage->id,
        ];

        // Clear input
        $this->message = '';

        // Create placeholder assistant message for streaming
        /** @var AIConversationMessage $assistantMessage */
        $assistantMessage = $conversation->messages()->create([
            'role' => 'assistant',
            'content' => '',
            'status' => AIMessageStatus::Pending,
        ]);

        $this->streamingMessageId = $assistantMessage->id;

        // Add placeholder to messages array (will be updated via WebSocket or sync)
        $this->messages[] = [
            'role' => 'assistant',
            'content' => '',
            'id' => $assistantMessage->id,
            'isStreaming' => true,
        ];

        // Check if streaming is enabled
        if (!$this->getStreamingEnabled()) {
            // Sync mode - no Reverb needed, process immediately
            $this->sendMessageSync($assistantMessage->id);

            return;
        }

        // Remote (host-proxied SSE) mode: the frontend opens an EventSource on
        // ChatStreamProxyController (customer or guest endpoint), which consumes
        // phasea's SSE and streams the host's 6-event schema back (CUT-02).
        // Phase 12: the local queued-job path (ProcessAIChatMessage) is retired —
        // remote SSE is the only streaming transport.
        //
        // Message-id handshake (30-B1, audit A2): hand the persisted ids to the
        // browser so it opens the SSE stream by reference — the proxy loads the
        // stored user message body server-side (the blade previously read an
        // UNDEFINED `pendingMessage`, sending message='' and 422ing every time).
        $this->dispatch(
            'customer-chat-message-created',
            conversationId: $conversation->id,
            userMessageId: $savedUserMessage->id,
            assistantMessageId: $assistantMessage->id,
        );

        $this->dispatch('chat-updated');
    }

    /**
     * Synchronous sendMessage for fallback or non-streaming mode.
     * Use this when WebSocket/Reverb is not available or disabled.
     *
     * @param  int|null  $messageId  If provided, updates an existing placeholder message
     *                               instead of creating new user/assistant messages.
     */
    public function sendMessageSync(?int $messageId = null): void
    {
        // If no messageId, this is a standalone call (legacy behavior)
        // If messageId provided, we're updating an existing placeholder
        $isUpdatingPlaceholder = $messageId !== null;

        if (!$isUpdatingPlaceholder) {
            $trimmedMessage = trim($this->message);

            if (empty($trimmedMessage)) {
                return;
            }

            // Rate limiting: 10 messages per minute per session
            $rateLimitKey = 'customer-ai-chat:'.($this->sessionId ?: request()->ip());
            if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
                $this->error = __('customer_ai.rate_limit_exceeded');

                return;
            }
            RateLimiter::hit($rateLimitKey, 60);
        }

        $this->error = null;
        $this->isLoading = true;

        // Determine what message to send to AI
        $userMessage = '';

        if ($isUpdatingPlaceholder) {
            // Get the user message from the conversation (already saved by sendMessage)
            $assistantMessage = AIConversationMessage::find($messageId);
            if (!$assistantMessage) {
                $this->error = __('customer_ai.error_generic');
                $this->isLoading = false;

                return;
            }

            // Get the latest user message from the same conversation
            /** @var AIConversationMessage|null $userMessageModel */
            $userMessageModel = AIConversationMessage::where('ai_conversation_id', $assistantMessage->ai_conversation_id)
                ->where('role', 'user')
                ->latest()
                ->first();

            $userMessage = $userMessageModel !== null ? ($userMessageModel->content ?? '') : '';
        } else {
            // Legacy standalone mode - sanitize and add user message to UI immediately
            /** @var string $sanitizedMessage */
            $sanitizedMessage = Purify::clean(trim($this->message));
            $this->messages[] = [
                'role' => 'user',
                'content' => $sanitizedMessage,
            ];

            $userMessage = $sanitizedMessage;
            $this->message = '';
        }

        // 30-B1 (audit A3): the sync fallback routes through the customer
        // assistant seam (`AiClient::customerChat`, phasea's
        // POST api/v1/ai/assistant/chat) instead of the generic admin `chat()`
        // surface, so persona/tools/rate-limiting match the streaming transport.
        // Failures map to cs12's friendly localized bubble — a phasea outage
        // must never surface as an unhandled Livewire exception.
        try {
            $chatResponse = app(AiClientFactory::class)
                ->for(AiSurface::CustomerChat)
                ->customerChat(new ChatRequest(
                    message: $userMessage,
                    conversationId: $this->conversationId !== null ? (string) $this->conversationId : null,
                    modelType: null,
                    modelId: null,
                    operationId: (string) Str::uuid(),
                    hostUserId: $this->resolveCustomerIdentity(),
                ));
        } catch (AiRateLimitedException) {
            $this->failSyncMessage($messageId, (string) __('customer_ai.rate_limit_exceeded'));

            return;
        } catch (AiClientException) {
            // AiBudgetExceededException and every other AiClientException
            // family member render the generic bubble.
            $this->failSyncMessage($messageId, (string) __('customer_ai.error_generic'));

            return;
        } catch (\Throwable $exception) {
            // Defensive backstop (cs12 parity): a widget failure must NEVER
            // surface as an unhandled Livewire exception.
            report($exception);
            $this->failSyncMessage($messageId, (string) __('customer_ai.error_generic'));

            return;
        }

        // Persist the assistant content to the placeholder row so refreshes and
        // history match the streaming transport (the local service did this).
        if ($isUpdatingPlaceholder) {
            $assistantMessage->update([
                'content' => $chatResponse->content,
                'status' => AIMessageStatus::Complete,
            ]);
        }

        $remoteConversationId = is_numeric($chatResponse->conversationId)
            ? (int) $chatResponse->conversationId
            : $this->conversationId;

        $this->isLoading = false;

        if ($chatResponse->content !== '') {
            $this->conversationId = $remoteConversationId;

            $parsedResponse = $this->parseAIResponse($chatResponse->content);

            if ($isUpdatingPlaceholder) {
                // Update the existing placeholder message in the messages array
                foreach ($this->messages as $index => $msg) {
                    if (isset($msg['id']) && $msg['id'] === $messageId) {
                        $this->messages[$index] = array_merge($parsedResponse, ['id' => $messageId]);
                        break;
                    }
                }
            } else {
                // Legacy mode - add new message
                $this->messages[] = $parsedResponse;
            }
        } else {
            $this->error = __('customer_ai.error_generic');

            // If updating placeholder, mark it as error
            if ($isUpdatingPlaceholder) {
                foreach ($this->messages as $index => $msg) {
                    if (isset($msg['id']) && $msg['id'] === $messageId) {
                        $this->messages[$index] = [
                            'role' => 'assistant',
                            'content' => __('customer_ai.error_generic'),
                            'id' => $messageId,
                        ];
                        break;
                    }
                }
            }
        }

        $this->streamingMessageId = null;

        // Dispatch event to scroll chat to bottom
        $this->dispatch('chat-updated');
    }

    /**
     * Resolve the phasea-scoped customer identity for the sync fallback
     * (30-B1, audit A3), mirroring the SSE proxy's identity brokering:
     * authenticated users map via `phasea_customer_id` (falling back to the
     * host PK), guests reuse the session+conversation scoped `guest:` hash
     * minted by `ChatStreamProxyController::guestStream()`.
     */
    protected function resolveCustomerIdentity(): string
    {
        $user = Auth::user();

        if ($user !== null) {
            /** @var string|null $mapped */
            $mapped = $user->getAttribute('phasea_customer_id');

            return is_string($mapped) && $mapped !== '' ? $mapped : (string) $user->getAuthIdentifier();
        }

        $sessionId = (string) session()->getId();

        return 'guest:'.hash('sha256', $sessionId.':'.($this->conversationId ?? ''));
    }

    /**
     * Finalize a failed sync-fallback exchange with a localized error bubble
     * (30-B1, audit A3): mark the placeholder row, replace the placeholder
     * bubble with the friendly message, and surface the error banner.
     */
    protected function failSyncMessage(?int $messageId, string $localizedMessage): void
    {
        $this->error = $localizedMessage;
        $this->isLoading = false;

        if ($messageId !== null) {
            AIConversationMessage::query()
                ->whereKey($messageId)
                ->update([
                    'status' => AIMessageStatus::Error,
                    'has_error' => true,
                    'error_type' => 'remote_sync',
                ]);

            foreach ($this->messages as $index => $msg) {
                if (isset($msg['id']) && $msg['id'] === $messageId) {
                    $this->messages[$index] = [
                        'role' => 'assistant',
                        'content' => $localizedMessage,
                        'id' => $messageId,
                    ];
                    break;
                }
            }
        }

        $this->streamingMessageId = null;

        $this->dispatch('chat-updated');
    }

    /**
     * Render a terminal stream `error` event as cs12's localized bubble
     * (30-B1, audit A4-phaseb): rate-limit/budget stream failures show a
     * friendly message instead of being console-logged into a dead widget.
     *
     * `$errorCode` is derived by the blade from the stream error payload
     * (`rate_limited`, `budget_exceeded`, or null for anything else).
     */
    public function handleStreamError(?string $errorCode = null): void
    {
        /** @var string $localizedMessage */
        $localizedMessage = $errorCode === 'rate_limited'
            ? __('customer_ai.rate_limit_exceeded')
            : __('customer_ai.error_generic');

        $this->error = $localizedMessage;

        if ($this->streamingMessageId !== null) {
            foreach ($this->messages as $index => $msg) {
                if (isset($msg['id']) && $msg['id'] === $this->streamingMessageId) {
                    $this->messages[$index] = [
                        'role' => 'assistant',
                        'content' => $localizedMessage,
                        'id' => $this->streamingMessageId,
                    ];
                    break;
                }
            }
        }

        $this->streamingMessageId = null;

        $this->dispatch('chat-updated');
    }

    /**
     * Reset streaming state after response completes.
     *
     * Called by frontend via $wire when streaming finishes (complete/error).
     * Updates the placeholder message in $messages with the actual content from the database.
     */
    public function resetStreamingState(): void
    {
        // Find and update the streaming message with actual content from database
        if ($this->streamingMessageId !== null) {
            $dbMessage = AIConversationMessage::find($this->streamingMessageId);

            if ($dbMessage !== null && !empty($dbMessage->content)) {
                // Find the placeholder in messages array and update it
                foreach ($this->messages as $index => $msg) {
                    if (isset($msg['id']) && $msg['id'] === $this->streamingMessageId) {
                        // Parse the response to extract product recommendations if any
                        // Note: parseAIResponse() returns array without 'isStreaming', so complete replacement removes it
                        $parsedResponse = $this->parseAIResponse($dbMessage->content);
                        $this->messages[$index] = array_merge($parsedResponse, ['id' => $this->streamingMessageId]);
                        break;
                    }
                }
            }
        }

        $this->streamingMessageId = null;
    }

    public function clearChat(): void
    {
        $this->conversationId = null;
        $this->messages = [];
        $this->error = null;
        $this->streamingMessageId = null;

        // Re-add welcome message
        $service = app(CustomerAIService::class);
        if ($service->isEnabled()) {
            $this->messages[] = [
                'role' => 'assistant',
                'content' => $service->getWelcomeMessage(),
            ];
        }
    }

    /**
     * Rate an AI response as helpful or not helpful.
     *
     * Persists the feedback to the database for analytics and AI improvement.
     */
    public function rateFeedback(int $messageIndex, string $feedbackType): void
    {
        if (!in_array($feedbackType, ['positive', 'negative'], true)) {
            return;
        }

        if ($messageIndex < 0 || $messageIndex >= count($this->messages)) {
            return;
        }

        if ($this->messages[$messageIndex]['role'] !== 'assistant') {
            return;
        }

        $messageId = $this->messages[$messageIndex]['id'] ?? null;

        if ($messageId !== null) {
            $message = $this->findOwnedMessage($messageId);

            if ($message !== null) {
                $feedbackType === 'positive' ? $message->markAsPositive() : $message->markAsNegative();

                Log::info('Customer AI feedback saved', [
                    'conversation_id' => $this->conversationId,
                    'message_id' => $messageId,
                    'feedback' => $feedbackType,
                ]);
            }
        }

        $this->messages[$messageIndex]['feedback'] = $feedbackType;
    }

    /**
     * Find a message owned by the current user/session (IDOR protection).
     */
    protected function findOwnedMessage(int $messageId): ?AIConversationMessage
    {
        return AIConversationMessage::query()
            ->where('id', $messageId)
            ->where('role', 'assistant')
            ->whereHas('conversation', fn ($q) => $q
                ->where('id', $this->conversationId)
                ->where(Auth::check() ? 'user_id' : 'session_id', Auth::check() ? Auth::id() : $this->sessionId)
            )
            ->first();
    }

    public function getIsAuthenticatedProperty(): bool
    {
        return Auth::check();
    }

    public function getContactUrlProperty(): string
    {
        $url = config('ai.assistant.customer_assistant.contact_url', '/contact');

        return is_string($url) ? $url : '/contact';
    }

    /**
     * Parse AI response to detect product recommendations.
     *
     * Extracts JSON product recommendations from the response if present.
     *
     * @return array{role: string, content: string, type?: string, products?: array<int, array<string, mixed>>}
     */
    protected function parseAIResponse(string $response): array
    {
        $baseMessage = [
            'role' => 'assistant',
            'content' => $response,
        ];

        // Try to extract JSON from code blocks first (most common format)
        $jsonStr = $this->extractJsonFromCodeBlock($response);
        $textContent = $response;

        if ($jsonStr !== null) {
            // Remove the code block from text content
            $textContent = preg_replace('/```(?:json)?\s*\{[\s\S]*?\}\s*```/s', '', $response);
            $textContent = trim((string) $textContent);
        } else {
            // Try to find standalone JSON object with product_recommendations
            $jsonStr = $this->extractJsonObject($response);
            if ($jsonStr !== null) {
                // Remove the JSON from text content
                $textContent = str_replace($jsonStr, '', $response);
                $textContent = trim($textContent);
            }
        }

        // Try to parse the extracted JSON
        if ($jsonStr !== null) {
            try {
                /** @var array<string, mixed> $data */
                $data = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);

                // Accept either {"type": "product_recommendations", "products": [...]}
                // or simpler {"products": [...]} format (AI sometimes omits the type)
                if ($this->isProductRecommendationsData($data)) {
                    $validatedProducts = $this->validateProducts($data['products']);

                    return [
                        'role' => 'assistant',
                        'content' => $textContent ?: (string) __('customer_ai.here_are_recommendations'),
                        'type' => 'product_recommendations',
                        'products' => $validatedProducts,
                    ];
                }
            } catch (\JsonException) {
                // JSON parsing failed
            }
        }

        // Also try to parse if the entire response is valid JSON
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

            if ($this->isProductRecommendationsData($data)) {
                $validatedProducts = $this->validateProducts($data['products']);
                $message = isset($data['message']) && is_string($data['message'])
                    ? $data['message']
                    : (string) __('customer_ai.here_are_recommendations');

                return [
                    'role' => 'assistant',
                    'content' => $message,
                    'type' => 'product_recommendations',
                    'products' => $validatedProducts,
                ];
            }
        } catch (\JsonException) {
            // Not valid JSON, return as plain text
        }

        return $baseMessage;
    }

    /**
     * Check if data contains product recommendations.
     *
     * Accepts both formats:
     * - {"type": "product_recommendations", "products": [...]}
     * - {"products": [...]} (simplified format AI sometimes uses)
     *
     * @param  array<string, mixed>  $data
     */
    protected function isProductRecommendationsData(array $data): bool
    {
        // Must have products array
        if (!isset($data['products']) || !is_array($data['products'])) {
            return false;
        }

        // If type is specified, it must be product_recommendations
        if (isset($data['type']) && $data['type'] !== 'product_recommendations') {
            return false;
        }

        // Check that products array contains objects with 'id' field
        if (empty($data['products'])) {
            return false;
        }

        $firstProduct = $data['products'][0] ?? null;

        return is_array($firstProduct) && isset($firstProduct['id']);
    }

    /**
     * Extract JSON from markdown code blocks.
     */
    protected function extractJsonFromCodeBlock(string $text): ?string
    {
        // Match ```json ... ``` or ``` ... ``` containing JSON
        if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/s', $text, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Extract a balanced JSON object from text using bracket matching.
     */
    protected function extractJsonObject(string $text): ?string
    {
        // Find the start of a JSON object that looks like product recommendations
        $startPos = strpos($text, '{"type"');
        if ($startPos === false) {
            $startPos = strpos($text, '{ "type"');
        }
        if ($startPos === false) {
            return null;
        }

        // Use bracket matching to find the complete JSON object
        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($text);

        for ($i = $startPos; $i < $length; $i++) {
            $char = $text[$i];

            if ($escape) {
                $escape = false;

                continue;
            }

            if ($char === '\\' && $inString) {
                $escape = true;

                continue;
            }

            if ($char === '"') {
                $inString = !$inString;

                continue;
            }

            if (!$inString) {
                if ($char === '{') {
                    $depth++;
                } elseif ($char === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return substr($text, $startPos, $i - $startPos + 1);
                    }
                }
            }
        }

        return null;
    }

    /**
     * Validate product data against the database for security.
     *
     * Ensures product IDs, slugs, and prices match actual database values
     * to prevent prompt injection attacks from displaying fake product data.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    protected function validateProducts(array $products): array
    {
        // D8 inversion: product-card validation/rendering is host-bound via
        // ChatProductCardContract — this package never queries domain models.
        return app(ChatProductCardContract::class)->validateCards($products);
    }

    /**
     * Check if streaming mode is enabled (Reverb WebSocket).
     * When disabled, uses synchronous mode (no Reverb required).
     */
    public function getStreamingEnabled(): bool
    {
        return (bool) config('ai.assistant.streaming.enabled', true);
    }

    /**
     * Resolve the effective driver for the `customer_chat` surface (CUT-02).
     *
     * Honors the per-surface override (`AI_SURFACE_CUSTOMER_CHAT_DRIVER`) and
     * falls back to the global `AI_DRIVER`. `local` is the rollback; `remote`
     * flips the panel to the host-proxied SSE transport.
     */
    public function getCustomerChatDriver(): string
    {
        // Phase 12 (CUT-06/07 final): the `local` rollback is retired. The
        // customer_chat surface resolves remote unconditionally — AiClientFactory
        // forces RemoteAiClient for it, and no in-process Prism chat path remains.
        return 'remote';
    }

    /**
     * Whether the customer chat panel should stream via the host-proxied SSE
     * transport (EventSource on ChatStreamProxyController). Always remote post
     * phase 12; only gated on whether streaming (vs the sync fallback) is enabled.
     */
    public function isRemoteStreaming(): bool
    {
        return $this->getStreamingEnabled();
    }

    /**
     * Get the fallback timeout in milliseconds.
     * If no WebSocket events arrive within this time, falls back to sync mode.
     */
    public function getFallbackTimeout(): int
    {
        return (int) config('ai.assistant.streaming.fallback_timeout', 5000);
    }

    public function render(): View
    {
        return view('ai-chat::livewire.front.customer-a-i-chat-panel');
    }
}
