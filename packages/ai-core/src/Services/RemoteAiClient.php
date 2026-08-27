<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\IssuesGuestStreamTokens;
use Core45\AiCore\DTOs\AiTextResult;
use Core45\AiCore\DTOs\ChatRequest;
use Core45\AiCore\DTOs\ChatResponse;
use Core45\AiCore\DTOs\ContentRequest;
use Core45\AiCore\DTOs\ContentResponse;
use Core45\AiCore\DTOs\DetectionRequest;
use Core45\AiCore\DTOs\DetectionResult;
use Core45\AiCore\DTOs\EmbeddingResult;
use Core45\AiCore\DTOs\HumanizationRequest;
use Core45\AiCore\DTOs\HumanizationResult;
use Core45\AiCore\DTOs\SearchResult;
use Core45\AiCore\DTOs\SeoResponse;
use Core45\AiCore\DTOs\StreamEvent;
use Core45\AiCore\DTOs\TranslationRequest;
use Core45\AiCore\DTOs\TranslationResponse;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Exceptions\AiBudgetExceededException;
use Core45\AiCore\Exceptions\AiClientException;
use Core45\AiCore\Exceptions\AiInvalidResponseException;
use Core45\AiCore\Exceptions\AiOperationAlreadyCompletedException;
use Core45\AiCore\Exceptions\AiProviderNotConfiguredException;
use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiRequestRejectedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Core45\AiCore\ModelRegistry;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * HTTP client implementation of `AiClient` — calls the phasea multi-tenant AI
 * service over HTTP for every non-streaming operation, and consumes the
 * upstream SSE stream for `streamChat()` (D17 re-emit synthesis).
 *
 * `streamChat()` opens a GET SSE connection to the service's 3-event stream
 * (chunk/tool_call/done) and re-emits the host's stable 6-event schema
 * (chunk/tool_start/tool_complete/error/done/heartbeat) as `StreamEvent` DTOs
 * so the host frontend never sees service-side schema changes.
 *
 * `createConversation()`/`closeConversation()` are state-mutating but carry
 * no `operationId` parameter on the `AiClient` contract. Per the 08-03
 * orchestrator note, this class mints a fresh `operation_id` (`Str::uuid()`)
 * per call for those two endpoints ONLY, so phasea's idempotency layer still
 * receives one — this is the sole exception to "operation_id is forwarded,
 * never minted" (every other method's operationId comes from the caller).
 *
 * `detect()`/`humanize()` submit work to phasea and return async operation
 * handles. Poll orchestration and persistence live in the phaseb operation
 * services; `pollOperation()` performs one GET and returns its flat body. The
 * no-throw flow adapter belongs at that job boundary, while this HTTP client
 * preserves the typed exception ladder.
 *
 * Security: the token SECRET is NEVER passed to `Log::`, `report()`, `dd()`,
 * `dump()`, or included in any exception message. The `Http::withToken()`
 * call is the only place the full token appears. Failure logs record the
 * status code, request method/path, operation_id, base-URL host, and the
 * token's numeric ID segment (`tokenIdForLogging()` — the part BEFORE the
 * "|", a Sanctum row PK that cannot authenticate on its own), never the
 * secret half, the request Authorization header, or the raw response body.
 */
class RemoteAiClient implements AiClient
{
    /**
     * Fixed UUIDv5 namespace for phaseb-minted embedding object identifiers.
     * phasea validates these as uuid format and uses them only as its own
     * storage keys. A fixed namespace keeps them deterministic. NOT used for
     * content generation's `object_id` anymore (13-REVIEW CR-01): that field
     * now carries the REAL host PK string-cast so phasea's agent tools can
     * resolve the object.
     */
    private const OBJECT_UUID_NAMESPACE = '3f2504e0-4f89-41d3-9a0c-0305e82c3301';

    /**
     * phasea's serialized ceiling for the C31-1 `record_context` snapshot —
     * mirrored client-side so oversized panels degrade instead of 422ing.
     */
    private const RECORD_CONTEXT_MAX_BYTES = 8192;

    private string $baseUrl;

    private string $token;

    private int $timeout;

    private int $timeoutMax;

    private int $connectTimeout;

    private int $idleTimeout;

    private int $totalTimeout;

    private int $heartbeatInterval;

    public function __construct(
        private readonly ModelRegistry $modelRegistry = new ModelRegistry,
    ) {
        $baseUrl = config('ai.base_url', '');
        $token = config('ai.token', '');
        $timeout = config('ai.timeout', 30);
        $timeoutMax = config('ai.timeout_max', 300);
        $connectTimeout = config('ai.stream.connect_timeout', 5);
        $idleTimeout = config('ai.stream.idle_timeout', 120);
        $totalTimeout = config('ai.stream.total_timeout', 600);
        $heartbeatInterval = config('ai.stream.heartbeat_interval', 15);

        $this->baseUrl = is_string($baseUrl) ? $baseUrl : '';
        $this->token = is_string($token) ? $token : '';
        $this->timeout = is_int($timeout) ? $timeout : 30;
        $this->timeoutMax = is_int($timeoutMax) ? $timeoutMax : 300;
        $this->connectTimeout = is_int($connectTimeout) ? $connectTimeout : 5;
        $this->idleTimeout = is_int($idleTimeout) ? $idleTimeout : 120;
        $this->totalTimeout = is_int($totalTimeout) ? $totalTimeout : 600;
        $this->heartbeatInterval = is_int($heartbeatInterval) ? $heartbeatInterval : 15;
    }

    public function chat(ChatRequest $request): ChatResponse
    {
        // phasea's chat endpoint reserves `conversation_id` for its OWN internal
        // UUIDs — a host-native conversation ID must be sent as
        // `host_conversation_id` (nullable string), mirroring the pattern
        // streamChat() already uses correctly (docs/ai-contract.md §1).
        $payload = [
            'message' => $request->message,
            'host_conversation_id' => $request->conversationId,
            'operation_id' => $request->operationId,
        ];

        // IN-07: phasea's ChatController validates `host_user_id` as a
        // nullable string — only add the key when the caller supplied one.
        if ($request->hostUserId !== null) {
            $payload['host_user_id'] = $request->hostUserId;
        }

        $payload = array_merge($payload, $this->recordContextFields($request));

        $data = $this->makeRequest('post', 'api/ai/chat', $payload, $request->operationId);

        return ChatResponse::fromArray($data);
    }

    /**
     * Build the additive C31-1 record-context wire fields from a ChatRequest.
     *
     * - `model_type` is mapped from a host model CLASS onto the neutral slug
     *   phasea expects (`post|product|category|webinar|…`) via ModelRegistry;
     *   already-neutral values pass through, unknown classes are dropped.
     * - `model_id` is string-cast (phasea validates `nullable|string`).
     * - `record_context` is clamped to phasea's 8KB serialized ceiling
     *   client-side so an oversized snapshot degrades instead of 422ing.
     *
     * Keys are only present when the caller supplied a value — absent fields
     * preserve the exact pre-Phase-31 wire shape (BC).
     *
     * @return array<string, mixed>
     */
    private function recordContextFields(ChatRequest $request): array
    {
        $fields = [];

        $modelType = $this->neutralModelSlug($request->modelType);

        if ($modelType !== null) {
            $fields['model_type'] = $modelType;
        }

        if ($request->modelId !== null) {
            $fields['model_id'] = (string) $request->modelId;
        }

        $recordContext = $this->clampRecordContext($request->recordContext);

        if ($recordContext !== null) {
            $fields['record_context'] = $recordContext;
        }

        if ($request->fieldReference !== null && $request->fieldReference !== '') {
            $fields['field_reference'] = mb_substr($request->fieldReference, 0, 255);
        }

        return $fields;
    }

    /**
     * Map a host model class onto the neutral entity slug phasea expects.
     *
     * Values without a namespace separator are assumed to already be neutral
     * slugs and pass through unchanged; unmapped classes return null so the
     * wire never carries a host-internal class name.
     */
    private function neutralModelSlug(?string $modelType): ?string
    {
        if ($modelType === null || $modelType === '') {
            return null;
        }

        if (!str_contains($modelType, '\\')) {
            return $modelType;
        }

        return $this->modelRegistry->getModelKey($modelType);
    }

    /**
     * Clamp a record-context snapshot to phasea's 8KB serialized ceiling.
     *
     * Oversized string values are truncated progressively; a snapshot that
     * still exceeds the ceiling afterwards is dropped entirely (null) rather
     * than risking a 422 on the whole chat turn.
     *
     * @param  array<string, mixed>|null  $recordContext
     * @return array<string, mixed>|null
     */
    private function clampRecordContext(?array $recordContext): ?array
    {
        if ($recordContext === null || $recordContext === []) {
            return null;
        }

        $encoded = json_encode($recordContext);

        if ($encoded !== false && strlen($encoded) <= self::RECORD_CONTEXT_MAX_BYTES) {
            return $recordContext;
        }

        foreach ($recordContext as $key => $value) {
            if (is_string($value) && mb_strlen($value) > 500) {
                $recordContext[$key] = mb_substr($value, 0, 500).'…';
            }
        }

        $encoded = json_encode($recordContext);

        if ($encoded !== false && strlen($encoded) <= self::RECORD_CONTEXT_MAX_BYTES) {
            return $recordContext;
        }

        return null;
    }

    /**
     * Synchronous customer-assistant chat (audit A3 — the widget's sync
     * fallback must hit the SAME phasea surface as its streaming path:
     * `POST api/v1/ai/assistant/chat`, persona/tools/tier limiting and
     * usage attribution included — not the generic admin `api/ai/chat`).
     *
     * Field mapping against phasea's `CustomerAssistantController::chat()`
     * validation (`message` required max:5000, `customer_id` required string,
     * `host_conversation_id` nullable string, `guest`/`guest_token`/
     * `guest_token_expires` for guest sessions, `operation_id` required uuid):
     *  - `ChatRequest::message`        → `message`
     *  - `ChatRequest::hostUserId`     → `customer_id` (the customer identity
     *    slot; REQUIRED here even though the admin surface treats it as
     *    optional). Guest sessions use the host-brokered `guest:` identity
     *    minted by the stream proxy (`'guest:'.hash(...)`) — that prefix
     *    cannot collide with real host customer PKs (stringified integers).
     *  - `ChatRequest::conversationId` → `host_conversation_id` (phasea
     *    reserves `conversation_id` for its own UUIDs, docs/ai-contract.md §1).
     *  - `ChatRequest::operationId`    → `operation_id` (forwarded, never
     *    minted — same as chat()).
     *
     * Guest identities additionally carry the signed short-lived guest token
     * (T-10-14-02), mirroring `streamCustomerChat()` — phasea's sync endpoint
     * runs the same `assertGuestToken()` gate as the stream.
     *
     * Response envelope `{"data": {"text", "conversation_id"}, "meta": {...}}`
     * parses through the same `ChatResponse::fromArray()` as chat().
     */
    public function customerChat(ChatRequest $request): ChatResponse
    {
        $customerId = $request->hostUserId;

        if ($customerId === null || trim($customerId) === '') {
            throw new \InvalidArgumentException(
                'customerChat requires the customer identity in ChatRequest::hostUserId.',
            );
        }

        $payload = [
            'message' => $request->message,
            'customer_id' => $customerId,
            'host_conversation_id' => $request->conversationId,
            'operation_id' => $request->operationId,
        ];

        if (str_starts_with($customerId, 'guest:')) {
            $brokered = app(IssuesGuestStreamTokens::class)->issue($customerId, (string) $request->conversationId);
            $payload['guest'] = true;
            $payload['guest_token'] = $brokered['token'];
            $payload['guest_token_expires'] = $brokered['expires'];
        }

        $data = $this->makeRequest('post', 'api/v1/ai/assistant/chat', $payload, $request->operationId);

        return ChatResponse::fromArray($data);
    }

    /**
     * Consume the customer chat upstream 3-event SSE (GET /api/v1/ai/assistant/chat/stream)
     * and re-emit the host's stable 6-event schema (Open Q4, D17).
     *
     * Mirrors `streamChat()` but targets the customer assistant surface, which
     * authenticates with a phasea-scoped `customer_id` (tenant+conversation
     * scoped on the service side) instead of the admin `ChatRequest` shape.
     *
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    public function streamCustomerChat(string $message, ?string $conversationId, string $customerId, string $operationId, bool $guest = false): \Generator
    {
        $url = "{$this->baseUrl}/api/v1/ai/assistant/chat/stream";

        // Validate operation_id format before sending (T-08-05b).
        if ($operationId !== '' && !Str::isUuid($operationId)) {
            throw new \InvalidArgumentException('operation_id must be a valid UUID.');
        }

        $query = [
            'message' => $message,
            'host_conversation_id' => $conversationId,
            'customer_id' => $customerId,
            'guest' => $guest,
            'operation_id' => $operationId,
        ];

        // Guest streams carry a signed, short-lived, tenant+conversation scoped
        // token (T-10-14-02) — phasea rejects anonymous identities without it.
        if ($guest) {
            $brokered = app(IssuesGuestStreamTokens::class)->issue($customerId, (string) $conversationId);
            $query['guest_token'] = $brokered['token'];
            $query['guest_token_expires'] = $brokered['expires'];
        }

        yield from $this->consumeSseStream($url, $query);
    }

    public function streamChat(ChatRequest $request): \Generator
    {
        $url = "{$this->baseUrl}/api/ai/chat/stream";

        // Validate operation_id format before sending (T-08-05b).
        if ($request->operationId !== '' && !Str::isUuid($request->operationId)) {
            throw new \InvalidArgumentException('operation_id must be a valid UUID.');
        }

        $query = [
            'message' => $request->message,
            'host_conversation_id' => $request->conversationId,
            'operation_id' => $request->operationId,
        ];

        // IN-07: only add the key when the caller supplied one.
        if ($request->hostUserId !== null) {
            $query['host_user_id'] = $request->hostUserId;
        }

        // C31-1: additive record-context fields (neutral model_type slug,
        // string model_id, clamped record_context, field_reference).
        $query = array_merge($query, $this->recordContextFields($request));

        yield from $this->consumeSseStream($url, $query);
    }

    /**
     * Open a GET SSE connection to an upstream phasea streaming endpoint and
     * re-emit the host's stable 6-event schema (chunk/tool_start/tool_complete/
     * error/done/heartbeat) as `StreamEvent` DTOs (D17).
     *
     * Shared by `streamChat()` (admin surface) and `streamCustomerChat()`
     * (customer surface) — both upstream endpoints emit the same 3-event schema
     * (chunk/tool_call/done), so the synthesis logic is identical.
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, StreamEvent, mixed, void>
     */
    private function consumeSseStream(string $url, array $query): \Generator
    {
        // Open the upstream SSE connection via GET with streaming enabled.
        try {
            $response = Http::withToken($this->token)
                ->withHeaders($this->clientIdentityHeaders())
                ->withOptions([
                    'stream' => true,
                    'connect_timeout' => $this->connectTimeout,
                    'timeout' => $this->totalTimeout,
                ])
                ->get($url, $query);
        } catch (ConnectionException) {
            yield new StreamEvent('error', ['message' => 'AI service connection failed'], 1);
            yield new StreamEvent('done', [], 1);

            return;
        } catch (\Throwable) {
            yield new StreamEvent('error', ['message' => 'AI service request failed'], 1);
            yield new StreamEvent('done', [], 1);

            return;
        }

        // Non-2xx initial response — synthesize error + done, do not throw.
        if (!$response->successful()) {
            yield new StreamEvent('error', [
                'message' => 'AI service returned HTTP '.$response->status(),
            ], 1);
            yield new StreamEvent('done', [], 1);

            return;
        }

        // Get the PSR-7 body and detach to a raw PHP stream resource so we
        // can apply stream_set_timeout for heartbeat synthesis.
        $psrBody = $response->toPsrResponse()->getBody();
        $resource = $psrBody->detach();

        if (!is_resource($resource)) {
            yield new StreamEvent('error', ['message' => 'AI service stream not readable'], 1);
            yield new StreamEvent('done', [], 1);

            return;
        }

        stream_set_timeout($resource, $this->heartbeatInterval);

        $buffer = '';
        $pendingToolStart = false;
        $lastToolName = '';
        $lastEventTime = time();
        $lastHeartbeat = time();
        $streamStartTime = time();
        $doneEmitted = false;

        try {
            while (!feof($resource)) {
                // Abort propagation: if the downstream client disconnected, stop.
                if (connection_aborted()) {
                    break;
                }

                // Total timeout guard.
                if ((time() - $streamStartTime) >= $this->totalTimeout) {
                    yield new StreamEvent('error', ['message' => 'AI service stream total timeout exceeded'], 1);
                    yield new StreamEvent('done', [], 1);

                    return;
                }

                $chunk = fread($resource, 8192);

                // Check if the read timed out (no data within heartbeatInterval).
                $meta = stream_get_meta_data($resource);

                if ($this->metaFlagIsTrue($meta, 'timed_out') && ($chunk === '' || $chunk === false)) {
                    // Idle timeout: if no data arrived for longer than idleTimeout, give up.
                    if ((time() - $lastEventTime) >= $this->idleTimeout) {
                        yield new StreamEvent('error', ['message' => 'AI service stream idle timeout exceeded'], 1);
                        yield new StreamEvent('done', [], 1);

                        return;
                    }

                    // No data within heartbeatInterval — emit heartbeat and continue.
                    yield new StreamEvent('heartbeat', [], 1);
                    $lastHeartbeat = time();
                    // Re-apply timeout for the next iteration.
                    stream_set_timeout($resource, $this->heartbeatInterval);

                    continue;
                }

                if ($chunk === false || $chunk === '') {
                    // Empty read WITHOUT the timed_out meta flag (non-blocking
                    // streams and php://temp test doubles omit it) would otherwise
                    // busy-spin, pegging a core (WR-03). Drive heartbeat + idle
                    // timeout off wall-clock time and sleep before continuing.
                    if ((time() - $lastEventTime) >= $this->idleTimeout) {
                        yield new StreamEvent('error', ['message' => 'AI service stream idle timeout exceeded'], 1);
                        yield new StreamEvent('done', [], 1);

                        return;
                    }

                    if ((time() - $lastHeartbeat) >= $this->heartbeatInterval) {
                        yield new StreamEvent('heartbeat', [], 1);
                        $lastHeartbeat = time();
                    }

                    usleep(50_000);

                    continue;
                }

                $buffer .= $chunk;
                $lastEventTime = time();
                $lastHeartbeat = time();

                // Split on SSE frame delimiter ("\n\n") and process complete frames.
                while (($delimiterPos = strpos($buffer, "\n\n")) !== false) {
                    $frame = substr($buffer, 0, $delimiterPos);
                    $buffer = substr($buffer, $delimiterPos + 2);

                    $parsed = $this->parseSseFrame($frame);

                    if ($parsed === null) {
                        continue;
                    }

                    // Synthesize the 6-event schema from the 3-event upstream.
                    $events = $this->synthesizeEvents($parsed, $pendingToolStart, $lastToolName);

                    foreach ($events as $event) {
                        if ($event->type === 'done') {
                            $doneEmitted = true;
                        }

                        yield $event;
                    }
                }
            }

            // If there is remaining buffer data, try to parse one last frame.
            $buffer = trim($buffer);

            if ($buffer !== '') {
                $parsed = $this->parseSseFrame($buffer);

                if ($parsed !== null) {
                    $events = $this->synthesizeEvents($parsed, $pendingToolStart, $lastToolName);

                    foreach ($events as $event) {
                        if ($event->type === 'done') {
                            $doneEmitted = true;
                        }

                        yield $event;
                    }
                }
            }

            // Clean EOF reached without any terminal frame (proxy truncation or
            // an upstream crash that still flushed a clean FIN) would leave the
            // host frontend — which closes the stream UI on `done` — hanging
            // forever. Synthesize a terminal error+done pair (WR-02).
            if (!$doneEmitted) {
                yield new StreamEvent('error', ['message' => 'AI service stream closed unexpectedly'], 1);
                yield new StreamEvent('done', [], 1);
            }
        } catch (\Throwable) {
            // Transport/read failure mid-stream — synthesize error then done.
            // Never include raw exception message (may contain URL/token info).
            yield new StreamEvent('error', ['message' => 'AI service stream interrupted'], 1);
            yield new StreamEvent('done', [], 1);

            return;
        } finally {
            // Always close the underlying stream resource to prevent leaks
            // (T-08-06c, Pitfall — unclosed upstream streams).
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }

    /**
     * phasea requires `object_type`, `object_id` (plain string, max:255 —
     * the host's REAL identifier, integer PK string-cast; the uuid-only
     * validation was relaxed for 13-REVIEW CR-01), `field_name`, `locale`,
     * `operation_id` (docs/ai-contract.md §2). The host caller
     * (`ContentAIService::generateContentRemote`) supplies model_type/
     * model_id/field via the ContentRequest context map; they are mapped here
     * onto phasea's fields. The real PK is transmitted (never a minted UUID)
     * so phasea's `ContentGenerationAgent` read tools can resolve the object
     * once the Phase 14 bridge repair lands — a fabricated UUID exists in
     * neither system and could never resolve.
     *
     * The context map's `custom_prompt`/`capability` have NO fields on
     * phasea's endpoint and are NOT transmitted — documented limitation
     * (docs/ai-contract.md §2 + Known Limitation #5; Phase 16 owns the
     * content surface flip).
     *
     * Explicit `objectType`/`objectId`/`fieldName` DTO fields (additive,
     * 13-04) take precedence over the context-map fallback when present.
     */
    public function generateContent(ContentRequest $request): ContentResponse
    {
        $contextModelType = $request->context['model_type'] ?? null;
        $contextModelId = $request->context['model_id'] ?? null;
        $contextField = $request->context['field'] ?? null;

        $objectType = $request->objectType
            ?? (is_string($contextModelType) && $contextModelType !== '' ? $contextModelType : 'content');

        // Real host identity — same pattern as generateSEO()'s content_id.
        // An empty model_id yields an empty string, which fails phasea's
        // required-field validation loudly (422 -> typed exception) instead
        // of silently generating context-free content.
        $objectId = $request->objectId
            ?? (is_scalar($contextModelId) ? (string) $contextModelId : '');

        $fieldName = $request->fieldName
            ?? (is_string($contextField) && $contextField !== '' ? $contextField : 'content');

        $data = $this->makeRequest('post', 'api/v1/ai/content/generate', [
            'object_type' => $objectType,
            'object_id' => $objectId,
            'field_name' => $fieldName,
            'locale' => $request->locale,
            'operation_id' => $request->operationId,
        ], $request->operationId);

        return ContentResponse::fromGeneratedContentEnvelope($data);
    }

    /**
     * phasea requires `content_id` (string — the host's real integer PK,
     * NOT a uuid), `content_type` (in:product,post,category), `locale`,
     * `operation_id` (docs/ai-contract.md §3). The subject model is supplied
     * via the `ContentRequest` context map (`model_type`/`model_id`) — the
     * generateContent-only `objectType`/`objectId` additive fields (13-04)
     * are intentionally NOT reused here: `object_id` there is a minted uuid
     * never round-tripped, whereas SEO's `content_id` must be the real host
     * PK (phasea's `SeoController` validates it as a plain string, not uuid).
     *
     * Response: a flat `{seo_title, seo_description, focus_keyphrase,
     * secondary_keyphrases, improvement_notes, meta}` envelope — parsed into
     * a dedicated `SeoResponse` DTO whose `metadata` accessor preserves the
     * real callers' (`SeoImprovementService`, `ContentAIService`,
     * `AIGenerateContentAction`) `$response->metadata['seo_title']` access
     * pattern unchanged.
     */
    public function generateSEO(ContentRequest $request): SeoResponse
    {
        $contextModelType = $request->context['model_type'] ?? null;
        $contextModelId = $request->context['model_id'] ?? null;

        $modelType = is_string($contextModelType) && $contextModelType !== '' ? $contextModelType : '';
        $modelId = is_scalar($contextModelId) ? (string) $contextModelId : '';

        // WR-01 (13-REVIEW): phasea's SeoController accepts an optional
        // `content_text` (nullable, max:50000) and appends it to the agent
        // prompt. Without it — and with the bridge read tools broken until
        // Phase 14 — phasea would generate SEO metadata blind while the host
        // holds the actual content in hand. Map the context's content field
        // onto the contract field, truncated to phasea's validation ceiling.
        $contextContent = $request->context['content'] ?? null;

        $payload = [
            'content_id' => $modelId,
            'content_type' => $this->mapSeoContentType($modelType),
            'locale' => $request->locale,
            'content_text' => is_string($contextContent) && $contextContent !== ''
                ? Str::limit($contextContent, 50000, '')
                : null,
            'operation_id' => $request->operationId,
            // Phase 33 pinned contract: the host decides whether phasea's
            // write-back may stamp `ai_rewritten_at`. Default false — cs12
            // never stamped it from the Improve SEO / Ask AI SEO surfaces.
            'mark_rewritten' => ($request->context['mark_rewritten'] ?? false) === true,
        ];

        // Phase 33 pinned contract: transmit the improvement context (current
        // values + fixable issues) when the caller assembled one, so phasea
        // can run its issue-driven prompt instead of the generic BC path.
        $improvementContext = $this->buildSeoImprovementContext($request->context);

        if ($improvementContext !== []) {
            $payload['context'] = $improvementContext;
        }

        $data = $this->makeRequest('post', 'api/v1/ai/content/seo', $payload, $request->operationId);

        return SeoResponse::fromArray($data);
    }

    /**
     * Map the host-side `ContentRequest` context onto phasea's phase-33
     * `context` object, clamped client-side to the pinned validation caps
     * (title 1000 / description 2000 / keyphrase 500 / issue id 100 /
     * label 1000 / suggested_fix 2000, at most 50 issues).
     *
     * Half-A guard: `has_keyphrase` is ONLY ever transmitted as true together
     * with a non-empty `current_focus_keyphrase` — never true with an empty
     * value (phasea's preservation enforcement keys on the pair).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildSeoImprovementContext(array $context): array
    {
        $improvementContext = [];

        $currentTitle = $context['seo_title'] ?? null;

        if (is_string($currentTitle) && $currentTitle !== '') {
            $improvementContext['current_seo_title'] = Str::limit($currentTitle, 1000, '');
        }

        $currentDescription = $context['seo_description'] ?? null;

        if (is_string($currentDescription) && $currentDescription !== '') {
            $improvementContext['current_seo_description'] = Str::limit($currentDescription, 2000, '');
        }

        $currentKeyphrase = $context['focus_keyphrase'] ?? null;

        if (is_string($currentKeyphrase) && trim($currentKeyphrase) !== '') {
            $improvementContext['current_focus_keyphrase'] = Str::limit($currentKeyphrase, 500, '');
            $improvementContext['has_keyphrase'] = true;
        }

        $fixableIssues = $context['fixable_issues'] ?? null;

        if (is_array($fixableIssues)) {
            $mappedIssues = [];

            foreach ($fixableIssues as $issue) {
                // BC: pre-33 callers passed bare issue-id strings.
                if (is_string($issue) && $issue !== '') {
                    $issue = ['id' => $issue];
                }

                if (!is_array($issue)) {
                    continue;
                }

                $issueId = $issue['id'] ?? null;

                if (!is_string($issueId) || $issueId === '') {
                    continue;
                }

                $entry = ['id' => Str::limit($issueId, 100, '')];

                $label = $issue['label'] ?? null;

                if (is_string($label) && $label !== '') {
                    $entry['label'] = Str::limit($label, 1000, '');
                }

                $suggestedFix = $issue['suggested_fix'] ?? null;

                if (is_string($suggestedFix) && $suggestedFix !== '') {
                    $entry['suggested_fix'] = Str::limit($suggestedFix, 2000, '');
                }

                $mappedIssues[] = $entry;

                if (count($mappedIssues) >= 50) {
                    break;
                }
            }

            if ($mappedIssues !== []) {
                $improvementContext['fixable_issues'] = $mappedIssues;
            }
        }

        return $improvementContext;
    }

    /**
     * Map a phaseb Eloquent model class to phasea's SEO `content_type` enum
     * (`in:product,post,category,webinar`). `webinar` joined the whitelist in
     * the Phase-30 parity fixes (audit F1 — EditWebinar registers the Improve
     * SEO action, so the previous throw hard-broke every webinar run).
     */
    private function mapSeoContentType(string $modelType): string
    {
        $contentType = $this->modelRegistry->getSeoContentType($modelType);

        if ($contentType === null) {
            throw new \InvalidArgumentException("Unsupported SEO content_type for model: {$modelType}");
        }

        return $contentType;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $query, string $locale, ?int $limit, string $operationId): array
    {
        $payload = [
            'query' => $query,
            'locale' => $locale,
            'operation_id' => $operationId,
        ];

        if ($limit !== null) {
            $payload['limit'] = $limit;
        }

        $data = $this->makeRequest('post', 'api/v1/ai/query', $payload, $operationId);

        return $this->unwrapDataEnvelope($data);
    }

    /**
     * @param  array<string, mixed>  $entityData
     * @return array<string, mixed>
     */
    public function previewAction(string $actionType, string $entityType, array $entityData, string $locale, string $operationId): array
    {
        $data = $this->makeRequest('post', 'api/v1/ai/action/preview', [
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_data' => $entityData,
            'locale' => $locale,
            'operation_id' => $operationId,
        ], $operationId);

        return $this->unwrapDataEnvelope($data);
    }

    /**
     * @param  array<string, mixed>  $entityData
     * @return array<string, mixed>
     */
    public function executeAction(string $actionType, string $entityType, array $entityData, string $locale, string $operationId, bool $confirmed = false): array
    {
        $data = $this->makeRequest('post', 'api/v1/ai/action/execute', [
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_data' => $entityData,
            'locale' => $locale,
            'operation_id' => $operationId,
            'confirmed' => $confirmed,
        ], $operationId);

        return $this->unwrapDataEnvelope($data);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getConversations(int $userId): array
    {
        // ALIGNED (docs/ai-contract.md §15): GET api/ai/conversations —
        // request/response already correctly matched against phasea's
        // ConversationController::index; no real caller in phaseb today.
        $data = $this->makeRequest('get', 'api/ai/conversations', [
            'user_id' => $userId,
        ], '');

        /** @var array<int, array<string, mixed>> */
        return $this->normalizeList($data['conversations'] ?? $data);
    }

    /**
     * @return array<string, mixed>
     */
    public function createConversation(int $userId, ?string $title = null): array
    {
        // No operationId on this contract method — mint one per the 08-03
        // orchestrator note so phasea's idempotency layer still receives one.
        $operationId = (string) Str::uuid();

        // ALIGNED (docs/ai-contract.md §16): POST api/ai/conversations.
        // phasea validates `user_id` as required|STRING — the host-native
        // integer must be cast, or the JSON integer fails validation (422).
        // Caught by the live contract suite (13-06); no real caller today.
        return $this->makeRequest('post', 'api/ai/conversations', [
            'user_id' => (string) $userId,
            'title' => $title,
            'operation_id' => $operationId,
        ], $operationId);
    }

    public function closeConversation(string $conversationId): bool
    {
        // No operationId on this contract method — mint one per the 08-03
        // orchestrator note so phasea's idempotency layer still receives one.
        $operationId = (string) Str::uuid();

        // ALIGNED (docs/ai-contract.md §17): POST api/ai/conversations/{id}/close
        // — request/response already correctly matched; no real caller today.
        $data = $this->makeRequest('post', "api/ai/conversations/{$conversationId}/close", [
            'operation_id' => $operationId,
        ], $operationId);

        return (bool) ($data['closed'] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchema(): array
    {
        return $this->modelRegistry->getSchemaForAI();
    }

    public function isEnabled(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    /**
     * phasea requires the host-native `content_id`, `content_type`
     * (in:product,post,category), `source_content`, `source_locale`,
     * `target_locale`, `operation_id`, and ALWAYS responds `202 {"status": "queued",
     * "operation_id"}` — translation is asynchronous-only; the result is
     * delivered via `ContentWriteBack::writeTranslation()` back to phaseb's
     * bridge, not in this response (docs/ai-contract.md §4). No polling logic
     * exists because no phasea polling endpoint exists (Phase 14 scope).
     *
     * The native ID is round-tripped by the write-back job, while source text
     * makes translation work before content-sync has established a mapping.
     */
    public function translate(TranslationRequest $request): TranslationResponse
    {
        $data = $this->makeRequest(
            'post',
            'api/v1/ai/content/translate',
            $request->toArray(),
            $request->operationId,
        );

        return TranslationResponse::fromArray($data);
    }

    public function rewriteContent(string $content, string $style, string $locale, string $operationId): string
    {
        $data = $this->makeRequest('post', 'api/ai/rewrite', [
            'content' => $content,
            'style' => $style,
            'locale' => $locale,
            'operation_id' => $operationId,
        ], $operationId);

        // phasea's envelope is {"data": {"rewritten_content": ..., "changes_summary": ...,
        // "tone_score": ...}} — not a top-level `content` key (docs/ai-contract.md §5).
        $rewritten = $this->unwrapDataEnvelope($data)['rewritten_content'] ?? null;

        if (!is_string($rewritten)) {
            // 13-REVIEW IN-04: a 200 with a missing/malformed envelope must
            // fail loudly on a LIVE surface — silently blanking rewritten
            // content is the exact drift class this phase eliminated.
            throw AiInvalidResponseException::forMalformedBody('', $operationId);
        }

        return $rewritten;
    }

    /**
     * phasea requires `texts` (array), `object_type`, `object_ref` (uuid) and
     * `operation_id` (uuid). embed() carries no operationId on the `AiClient`
     * contract, so one is minted per call; `object_ref` is a deterministic
     * phaseb-minted UUID satisfying phasea's format validation — phasea never
     * round-trips it back (docs/ai-contract.md §6, T-13-10).
     *
     * The response vector comes from `data.vectors[0]` (Plan 13-02).
     *
     * @return array<int, float>|null
     */
    public function embed(string $text): ?array
    {
        $operationId = (string) Str::uuid();

        $data = $this->makeRequest('post', 'api/ai/embeddings', [
            'texts' => [$text],
            'object_type' => 'embedding',
            'object_ref' => Uuid::uuid5(self::OBJECT_UUID_NAMESPACE, "embed:{$text}")->toString(),
            'operation_id' => $operationId,
        ], $operationId);

        return EmbeddingResult::fromArray($data)->vectors;
    }

    /**
     * Delegates to N sequential embed() calls — phasea's actual batch route
     * (`api/ai/embeddings/batch`) is async-only (Horizon-queued, returns
     * `{job_count, status: queued}` with no vectors) and cannot satisfy this
     * synchronous contract (docs/ai-contract.md §7). Zero real callers exist,
     * so sequential calls are not a performance regression.
     *
     * @param  array<int, string>  $texts
     * @return array<int, array<int, float>|null>
     */
    public function embedBatch(array $texts): array
    {
        $vectors = [];

        foreach ($texts as $text) {
            $vectors[] = $this->embed($text);
        }

        return $vectors;
    }

    public function summarize(string $text, string $operationId): string
    {
        // ALIGNED (docs/ai-contract.md §18): POST api/ai/summarize —
        // request/response already correctly matched; do not break.
        $data = $this->makeRequest('post', 'api/ai/summarize', [
            'text' => $text,
            'operation_id' => $operationId,
        ], $operationId);

        $summary = $data['summary'] ?? null;

        if (!is_string($summary)) {
            // 13-REVIEW IN-04: fail loudly on a malformed 200 envelope —
            // ContentSummarizerService (the live caller) catches Throwable
            // and falls back to a plain-text excerpt, so this degrades
            // gracefully instead of silently.
            throw AiInvalidResponseException::forMalformedBody('', $operationId);
        }

        return $summary;
    }

    /**
     * phasea returns `data.keyphrases` as structured objects
     * `{keyphrase, reason, difficulty}` (phase-33 pinned contract — nominally
     * exactly 5, but consumed defensively: fewer/malformed entries are
     * tolerated and skipped). `difficulty` maps onto the caller's
     * `estimatedDifficulty` key (`KeyphraseResearchService` shape); values
     * outside easy|medium|hard normalize to medium.
     *
     * `content_excerpt` (nullable, phasea cap max:2000) carries a plain-text
     * excerpt of the page body so the agent researches against real content
     * instead of the bare topic (cs12 parity — its prompt embedded the
     * caller's 500-char excerpt).
     *
     * @return array<int, array<string, mixed>>
     */
    public function keyphraseResearch(string $topic, string $locale, string $operationId, ?string $contentExcerpt = null): array
    {
        $payload = [
            'topic' => $topic,
            'locale' => $locale,
            'operation_id' => $operationId,
        ];

        if (is_string($contentExcerpt) && trim($contentExcerpt) !== '') {
            $payload['content_excerpt'] = Str::limit($contentExcerpt, 2000, '');
        }

        $data = $this->makeRequest('post', 'api/v1/ai/content/seo/keyphrase', $payload, $operationId);

        $keyphrases = $this->unwrapDataEnvelope($data)['keyphrases'] ?? [];
        $keyphrases = is_array($keyphrases) ? $keyphrases : [];

        $structured = [];

        foreach ($keyphrases as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $phrase = $entry['keyphrase'] ?? null;

            if (!is_string($phrase) || trim($phrase) === '') {
                continue;
            }

            $reason = $entry['reason'] ?? '';
            $difficulty = $entry['difficulty'] ?? '';

            $structured[] = [
                'keyphrase' => trim($phrase),
                'reason' => is_string($reason) ? $reason : '',
                'estimatedDifficulty' => in_array($difficulty, ['easy', 'medium', 'hard'], true)
                    ? $difficulty
                    : 'medium',
            ];
        }

        return $structured;
    }

    /**
     * Grounded web search via phasea (`POST api/v1/ai/search/grounded`,
     * Phase 32 — audit C1/C2): phasea holds the vendor keys, resolves its
     * own default upstream backend, runs the grounded call, meters it as
     * task `grounded_search`, and returns normalized sources.
     *
     * v2.6: `provider` is NEVER sent — the host does not choose the search
     * backend; phasea decides which vendor answers a grounded search.
     *
     * Request: `{query ≤2000 (clamped client-side), max_results 1..20,
     * operation_id uuid}`. Response envelope: `{"data": {"sources":
     * [{url, domain, title, snippet, published_at}], "provider", "model",
     * "meta"}}`. Sources map onto the blog research pipeline's
     * `SearchResult` DTO; `language`/`reliabilityScore`/`ogImage`/`images`
     * keep their defaults — phasea never populates them.
     *
     * Errors ride the standard typed ladder (429 budget/rate, 409 replays as
     * 4xx rejection, 502 unavailable); a 422 `{code: unsupported_provider}`
     * (phasea's default vendor unconfigured on the service) surfaces as
     * `AiProviderNotConfiguredException` — a config error, never a silent
     * empty result set.
     *
     * @return list<SearchResult>
     *
     * @throws AiClientException
     */
    public function groundedSearch(string $query, int $maxResults, string $operationId): array
    {
        $data = $this->makeRequest('post', 'api/v1/ai/search/grounded', [
            'query' => Str::limit($query, 2000, ''),
            'max_results' => max(1, min($maxResults, 20)),
            'operation_id' => $operationId,
        ], $operationId);

        $sources = $this->unwrapDataEnvelope($data)['sources'] ?? [];

        $results = [];

        foreach ($this->normalizeList($sources) as $source) {
            $url = $source['url'] ?? null;

            if (!is_string($url) || $url === '') {
                continue;
            }

            $domain = $source['domain'] ?? null;
            $title = $source['title'] ?? null;
            $snippet = $source['snippet'] ?? null;
            $publishedAt = $source['published_at'] ?? null;

            $results[] = new SearchResult(
                url: $url,
                domain: is_string($domain) && $domain !== '' ? $domain : ((parse_url($url, PHP_URL_HOST) ?: null) ?? 'unknown'),
                title: is_string($title) ? $title : '',
                snippet: is_string($snippet) ? $snippet : '',
                publishedAt: is_string($publishedAt) && $publishedAt !== '' ? $publishedAt : null,
            );
        }

        return $results;
    }

    /**
     * Generate an image from a PHASEA-OWNED image prompt (v2.6 design §2.5).
     *
     * The image counterpart of `runPrompt()`, and since plan 48-21 (F3) the ONLY
     * way this client generates an image: the body carries a template key plus
     * structured parameters and NO `prompt` key, so the host holds no image
     * instruction text. The raw `generateImage(string $prompt, …)` twin that used
     * to sit above this method was deleted — it had zero callers in `app/` and it
     * was an open raw-instruction seam that no architectural test covered.
     *
     * `model` is NOT sent: phasea resolves the image model from its own config.
     * The host does not choose models. Do not reinstate this key.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function generateImageFromTemplate(string $template, array $parameters, array $params, string $operationId): array
    {
        $style = $params['style'] ?? null;
        $aspectRatio = $params['aspect_ratio'] ?? null;

        $data = $this->makeRequest('post', 'api/ai/image/generate', [
            'prompt_template' => $template,
            'parameters' => $parameters,
            'style' => is_string($style) ? $style : null,
            'aspect_ratio' => is_string($aspectRatio) && $aspectRatio !== '' ? $aspectRatio : null,
            'operation_id' => $operationId,
        ], $operationId, $this->timeoutFromOptions($params));

        return $this->fetchGeneratedImage($data, $operationId);
    }

    /**
     * Unwrap phasea's image envelope and fetch the generated bytes.
     *
     * Kept separate from `generateImageFromTemplate()` so the response handling —
     * envelope unwrapping, `url` validation, the secondary byte-fetch and the
     * typed failure — reads as its own concern. It was shared with the deleted
     * raw `generateImage()` twin (48-21, F3).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fetchGeneratedImage(array $data, string $operationId): array
    {
        $envelope = $this->unwrapDataEnvelope($data);
        $url = $envelope['url'] ?? null;

        if (!is_string($url) || $url === '') {
            throw AiInvalidResponseException::forMalformedBody('', $operationId);
        }

        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) && $host !== '' ? $host : 'unknown';

        try {
            $imageResponse = Http::timeout($this->timeout)->get($url);
        } catch (\Throwable) {
            // T-13-11: never log the URL (may carry query params/tokens) or
            // any response body — host only.
            throw AiInvalidResponseException::forUnfetchableImageUrl($host, $operationId);
        }

        if (!$imageResponse->successful()) {
            throw AiInvalidResponseException::forUnfetchableImageUrl($host, $operationId);
        }

        $contentType = $imageResponse->header('Content-Type');

        return [
            'bytes' => $imageResponse->body(),
            'mime_type' => $contentType !== '' ? $contentType : 'application/octet-stream',
            'provider' => 'phasea',
            'url' => $url,
        ];
    }

    /**
     * phasea requires `media_id` (resolved server-side via
     * `CoreDataBridge::getMedia()`) instead of a raw `image_url`, and returns
     * `{"data": {"analysis", "image_url", "media_id", "analysis_type",
     * "meta"}}` — there is no dedicated `alt_text` key; `analysis` is the
     * closest available approximation (docs/ai-contract.md §13). Depends on
     * Phase 14's bridge fix for `media_id` resolution to succeed end-to-end;
     * zero real callers exist today (contract-only).
     */
    public function generateAltText(string $mediaId, string $locale, string $operationId): string
    {
        $data = $this->makeRequest('post', 'api/v1/ai/content/image-analysis', [
            'media_id' => $mediaId,
            'locale' => $locale,
            'operation_id' => $operationId,
        ], $operationId);

        $analysis = $this->unwrapDataEnvelope($data)['analysis'] ?? null;

        if (!is_string($analysis)) {
            // 13-REVIEW IN-04: fail loudly on a malformed 200 envelope.
            throw AiInvalidResponseException::forMalformedBody('', $operationId);
        }

        return $analysis;
    }

    /**
     * Generic single-turn text completion over phasea (BlogPipeline/BlogTopics
     * surfaces).
     *
     * Every surface shares phasea's single `api/v1/ai/text/complete`
     * completion route (Open Q7: the BlogTopics surface had no distinct
     * phasea endpoint at cutover; it reuses the same completion route —
     * the per-surface `AI_SURFACE_*_DRIVER` keys were deleted in 13-03).
     * phasea selects its own model; the response text is mapped back into
     * an `AiTextResult`.
     *
     * v2.6: `provider`/`model` are NEVER sent, even if a caller populates
     * them in `$options` (a regression none of today's callers commit, but
     * the seam strips them unconditionally so the rule holds regardless —
     * the same defense `generateImageFromTemplate()` and `sanitizeHumanizationOptions()`
     * already apply for their surfaces). The host does not choose a model
     * anywhere; do not reinstate this key.
     *
     * @param  array<string, mixed>  $options
     */
    public function completeText(string $systemPrompt, string $userPrompt, array $options, AiSurface $surface, string $operationId): AiTextResult
    {
        // v2.6: strip any caller-supplied provider/model/reasoning_effort
        // before it reaches the transmitted `options` map below — phasea
        // ignores them today, but the host must never forward a model,
        // provider, or reasoning-effort choice it does not make.
        unset($options['provider'], $options['model'], $options['reasoning_effort']);

        // Truncate to phasea's documented validation ceilings
        // (docs/ai-contract.md §19: system_prompt max:50000, user_prompt
        // max:100000) — 16-REVIEW MN-01. The old local path had no ceiling, so
        // an oversized prompt (large analytics period, long post rewrite)
        // would otherwise 422 as an opaque failure. Mirrors generateSEO()'s
        // Str::limit-to-contract-ceiling precedent.
        // Per-call timeout budget (audit 5.1): callers already pass
        // `options['timeout']` (translation 240, linking 120, GBP/analytics
        // 300) — honor it locally (clamped to `ai.timeout_max`) instead of
        // silently applying the global 30s default to every long generation.
        // The option stays in the transmitted `options` map too (phasea
        // validates-then-ignores it; advisory info is harmless).
        // system_prompt's 50000 matches phasea's UNCHANGED system ceiling. The
        // user_prompt ceiling tracks phasea's `max_body_chars` (2 000 000 since phase
        // 42) — NOT the stale 100 000 that predated server-side chunking. With the
        // whole body now sent in one call, a 100 000 cap would silently truncate a
        // long post; the config default equals phasea's ceiling so a genuine overflow
        // 422s loudly at phasea instead of being decapitated here.
        $limitedSystemPrompt = Str::limit($systemPrompt, 50000, '');
        $limitedUserPrompt = Str::limit(
            $userPrompt,
            max(1, (int) config('ai.assistant.translation.max_body_chars', 2000000)),
            '',
        );

        // Truncation observability (audit 5.3): large inputs subtly change
        // outputs — make the clamp visible instead of silent so operators
        // can chunk at the caller or raise phasea's ceilings.
        if (strlen($limitedSystemPrompt) < strlen($systemPrompt) || strlen($limitedUserPrompt) < strlen($userPrompt)) {
            Log::channel('ai')->warning('RemoteAiClient: completeText prompt truncated to contract ceilings', [
                'surface' => $surface->value,
                'operation_id' => $operationId,
                'system_prompt_original_length' => strlen($systemPrompt),
                'system_prompt_truncated_length' => strlen($limitedSystemPrompt),
                'user_prompt_original_length' => strlen($userPrompt),
                'user_prompt_truncated_length' => strlen($limitedUserPrompt),
            ]);
        }

        $data = $this->makeRequest('post', 'api/v1/ai/text/complete', [
            'surface' => $surface->value,
            'system_prompt' => $limitedSystemPrompt,
            'user_prompt' => $limitedUserPrompt,
            'options' => $options,
            'operation_id' => $operationId,
        ], $operationId, $this->timeoutFromOptions($options));

        // ASYNC (server-side chunking, phasea phase 42): a long body that phasea
        // chunks server-side returns the flat 202 operation envelope
        // {operation_id, surface, status} INSTEAD of synchronous {text, …}. When
        // it does, inline-poll the operation to completion and return its result —
        // so the caller sees the same AiTextResult whichever path phasea took.
        //
        // Detected by SHAPE, not status code (makeRequest returns only the body):
        // the async envelope carries `status` and no `text`; a synchronous result
        // carries `text` and no `status`. Only `text/complete` translate bodies go
        // async today, so in practice this branch is translate-only — but keying on
        // shape keeps it correct for any surface phasea later makes async.
        if (array_key_exists('status', $data) && !array_key_exists('text', $data)) {
            return $this->pollTextCompleteToCompletion($operationId);
        }

        return AiTextResult::fromArray($data);
    }

    /**
     * Run a phasea-owned prompt by template key (v2.6 design §2.5).
     *
     * Deliberately carries NO prompt text: the payload is a template key plus
     * structured parameters, and phasea's `PromptRegistry` composes the
     * instruction. Response envelope is the same `{text, prompt_tokens,
     * completion_tokens}` as `text/complete`, so it parses through the same DTO.
     *
     * `$options` is transport-only (a per-call `timeout` budget, clamped by
     * `ai.timeout_max` in `makeRequest`) and is deliberately NOT serialised into
     * the body: phasea's `PromptRunController` validates a closed set of
     * top-level keys, and an extra one is both contract drift and a place a host
     * could smuggle instruction text through. The timeout is read from
     * `$options`, never from `$parameters` — parameters are template input, and
     * conflating the two left every surface on the 30s default regardless of
     * what it asked for.
     *
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $options
     */
    public function runPrompt(string $template, array $parameters, string $operationId, array $options = []): AiTextResult
    {
        $data = $this->makeRequest('post', 'api/v1/ai/prompt/run', [
            'template' => $template,
            'parameters' => $parameters,
            'operation_id' => $operationId,
        ], $operationId, $this->timeoutFromOptions($options));

        return AiTextResult::fromArray($data);
    }

    /**
     * List phasea-owned prompt templates for display (v2.6 design §2.5).
     *
     * A free, unbilled GET read — the counterpart of `runPrompt()`. phasea
     * wraps the list in a `data` envelope; callers want the list, so unwrap it.
     *
     * @return array<int, array{key: string, task: string, system_prompt: string, parameter_keys: array<int, string>}>
     */
    public function listPromptTemplates(): array
    {
        $data = $this->makeRequest('get', 'api/v1/ai/prompt/templates', [], '');

        /** @var array<int, array{key: string, task: string, system_prompt: string, parameter_keys: array<int, string>}> */
        return array_values($data['data'] ?? $data);
    }

    /**
     * The prompt-contract capabilities phasea advertises (plan 48-22).
     *
     * The same free, unbilled catalog GET the two readers above use, off
     * `meta.capabilities`. A response without the key — an older phasea, or one
     * that advertises nothing — yields an empty list, and callers treat that as
     * "assume nothing" rather than "supported".
     *
     * ─────────────────────────────────────────────────────────────────────────
     * A JSON OBJECT IS NOT A CAPABILITY LIST (plan 48-23). The previous
     * implementation was `array_values(array_filter($capabilities, 'is_string'))`,
     * and `is_array()` is true for a PHP associative array — so a response body
     * carrying `{"preferred": "blog_pipeline.module.typed_placeholders"}` had its
     * VALUES filtered and re-indexed into a perfectly plausible list, and read as
     * SUPPORT. A malformed or differently-shaped answer must never be
     * indistinguishable from a positive one, so the shape is checked with
     * `array_is_list()` before anything is trusted, and empty-string entries are
     * dropped because they can only ever match nothing.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return array{disposition: 'advertised'|'malformed', capabilities: list<string>, release: string}
     */
    public function listServiceCapabilities(): array
    {
        $data = $this->makeRequest('get', 'api/v1/ai/prompt/templates', [], '');

        $meta = $data['meta'] ?? null;
        $release = is_array($meta) && is_string($meta['release'] ?? null) && $meta['release'] !== ''
            ? $meta['release']
            : 'unknown';

        if (!is_array($meta) || !array_key_exists('capabilities', $meta)) {
            // NOT malformed — this is precisely what every phasea deployed before
            // 48-22 returns, and "the key is absent" is a well-formed answer
            // meaning "nothing is advertised".
            return ['disposition' => 'advertised', 'capabilities' => [], 'release' => $release];
        }

        $capabilities = $meta['capabilities'];

        if (!is_array($capabilities) || !array_is_list($capabilities)) {
            return ['disposition' => 'malformed', 'capabilities' => [], 'release' => $release];
        }

        $strings = array_values(array_filter(
            $capabilities,
            static fn (mixed $entry): bool => is_string($entry) && $entry !== '',
        ));

        // A LIST WITH A NON-STRING OR AN EMPTY STRING IN IT IS ALSO MALFORMED, and
        // it yields NO capabilities rather than the surviving entries. Returning
        // the survivors would let a partly-broken advertisement read as a shorter
        // valid one — the same class of mistake as the object above, and the same
        // consequence: a wrong "yes".
        if (count($strings) !== count($capabilities)) {
            return ['disposition' => 'malformed', 'capabilities' => [], 'release' => $release];
        }

        return [
            'disposition' => 'advertised',
            'capabilities' => $strings,
            'release' => $release,
        ];
    }

    /**
     * List phasea-owned tenant prompt-override EXAMPLES for display (48-20).
     *
     * The SAME free, unbilled GET as `listPromptTemplates()` — the examples ride
     * that read's `meta.override_examples` rather than a route of their own,
     * because the catalog endpoint already exists so a host can display prompt
     * text it does not own. A response without the key (an older phasea) yields
     * an empty map, and the admin form degrades to its "no examples" state.
     *
     * @return array<string, array<int, array{title: string, content: string, type: string}>>
     */
    public function listPromptOverrideExamples(): array
    {
        $data = $this->makeRequest('get', 'api/v1/ai/prompt/templates', [], '');

        $examples = $data['meta']['override_examples'] ?? [];

        /** @var array<string, array<int, array{title: string, content: string, type: string}>> */
        return is_array($examples) ? $examples : [];
    }

    /**
     * Inline-poll a phasea async `text/complete` operation to completion and return
     * its result as the same `AiTextResult` the synchronous path returns (phaseb
     * phase 11 / D2, D3).
     *
     * INLINE, not the detect/humanize re-dispatching poller: `completeText`'s caller
     * (`translateToLocale`) consumes the text inline, and `TranslatePostToLocale` is a
     * queued job that already blocks up to `$timeout = 3600` on the pre-cutover chunk
     * loop, so blocking a worker on the poll is the same profile, not a regression.
     *
     * TERMINAL STATES ARE TRANSLATE'S, NOT DETECT'S (D3). `done` is the only success;
     * `queued`/`processing` are the only non-terminal states; EVERY other terminal
     * status (`failed`, `operation_expired`, the SpendOutcomeUnknown analog) is a
     * failure. Translate never writes detect's `submission_outcome_unknown`, so it is
     * deliberately not special-cased.
     *
     * RETRY / BILLING SAFETY (D5). A timeout here throws a transient error; the job
     * retries with the SAME deterministic `operation_id` and re-POSTs the SAME body,
     * so phasea `findExisting` REPLAYS the 202 (no re-bill, no re-run) and this poll
     * resumes. The initial POST bills once; every poll GET is a free read.
     */
    private function pollTextCompleteToCompletion(string $operationId): AiTextResult
    {
        $maxSeconds = max(1, (int) config('ai.assistant.translation.poll_max_seconds', 3300));
        $intervalSeconds = max(1, (int) config('ai.assistant.translation.poll_interval_seconds', 3));
        $deadline = microtime(true) + $maxSeconds;

        while (true) {
            $body = $this->pollOperation('translate', $operationId);
            $status = $body['status'] ?? null;

            if ($status === 'done') {
                $result = $body['result'] ?? null;

                if (!is_array($result)) {
                    // done with no result payload is a contract breach — fail loud
                    // rather than return empty text (a silently-blank translation).
                    throw AiInvalidResponseException::forMalformedBody(
                        json_encode($body) ?: '(unencodable body)',
                        $operationId,
                    );
                }

                /** @var array<string, mixed> $result */
                return AiTextResult::fromArray($result);
            }

            // Only queued/processing are non-terminal. Any other status is terminal:
            // done handled above, everything else is a failure.
            if (!in_array($status, ['queued', 'processing'], true)) {
                throw AiUnavailableException::forStatus(0, $operationId);
            }

            if (microtime(true) >= $deadline) {
                // Give up THIS attempt (transient). The job retry re-POSTs the same
                // id → phasea replays → this poll resumes. No re-bill (D5).
                throw AiUnavailableException::forStatus(0, $operationId);
            }

            usleep($intervalSeconds * 1_000_000);
        }
    }

    /**
     * Trigger a full blog pipeline run on phasea.
     *
     * phasea requires `topic_id` (uuid) + `locale` and returns a `data`-wrapped
     * `BlogPipelineResource` envelope (`{"data": {"content", "topic_id",
     * "status", "meta": {"contract_version", "operation_id"}}}`), which is
     * unwrapped here so callers get the flat `{content, topic_id, status,
     * meta}` payload — note `operation_id` is nested under `meta`, not
     * flattened to the top of `data` (docs/ai-contract.md §21).
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function triggerBlogPipeline(string $topicId, string $locale, array $config, string $operationId): array
    {
        $payload = [
            'topic_id' => $topicId,
            'locale' => $locale,
            'operation_id' => $operationId,
        ];

        if (isset($config['pipeline_run_id']) && is_string($config['pipeline_run_id'])) {
            $payload['pipeline_run_id'] = $config['pipeline_run_id'];
        }

        $data = $this->makeRequest('post', 'api/v1/ai/content/blog-pipeline', $payload, $operationId);

        // 13-REVIEW IN-09: use the shared helper — the previous
        // `$data['data'] ?? $data` whole-body fallback diverged from the
        // e5d1ddc consolidation of the other envelope-unwrapping call sites.
        return $this->unwrapDataEnvelope($data);
    }

    /**
     * phasea validates top-level `type` (in:positive,negative,neutral),
     * `content` and `metadata` — no `feedback` wrapper and no `operation_id`
     * field on this endpoint (docs/ai-contract.md §14).
     *
     * Mapping from the host's conversation-message feedback shape
     * (`FeedbackAnalysisService`): `has_error === true` maps to `negative`,
     * otherwise `neutral` (conversation feedback has no inherent blog-post
     * sentiment); `feedback_reason` becomes `content`; the full original
     * payload rides in `metadata` for traceability.
     *
     * Success is HTTP 201 — phasea returns `{data: {record}}` with no
     * `accepted` key; makeRequest() throwing on non-2xx is the failure signal.
     *
     * @param  array<string, mixed>  $feedback
     */
    public function submitFeedback(array $feedback, string $operationId): bool
    {
        $content = $feedback['feedback_reason'] ?? null;

        $this->makeRequest('post', 'api/ai/feedback', [
            'type' => ($feedback['has_error'] ?? null) === true ? 'negative' : 'neutral',
            // Clamp to phasea's `content` validation ceiling (max:5000,
            // FeedbackController) — an oversized reason must not 422 the
            // mirror call (audit 5.3). The full reason still rides in
            // metadata, which has no length rule.
            'content' => is_string($content) ? mb_substr($content, 0, 5000) : null,
            'metadata' => $feedback,
        ], $operationId);

        return true;
    }

    /**
     * Fetch the tenant's budget status from phasea (`GET api/ai/budget/status`).
     *
     * Zero-LLM-spend authenticated probe: a 200 proves DNS + TLS + token +
     * tenant binding end-to-end, which is exactly what `ai:status --live`
     * needs (audit 5.3). Returns the unwrapped `data` payload:
     * `{spent, cap, ratio, alert_triggered, exceeded}`.
     *
     * @return array<string, mixed>
     */
    public function getBudgetStatus(): array
    {
        $data = $this->makeRequest('get', 'api/ai/budget/status', [], '');

        return $this->unwrapDataEnvelope($data);
    }

    /**
     * Detect conflicts across the host-owned knowledge base (knowledge surface).
     *
     * Phasea's `GET api/ai/knowledge/conflicts` returns a Laravel PAGINATOR
     * envelope — the conflict list rides under the top-level `data` key
     * (`{"current_page": 1, "data": [...], ...}`), with UUID-string
     * `knowledge_a_id`/`knowledge_b_id` referencing phasea's own
     * `LearnedKnowledge` rows (audit D1 — the previous `conflicts` key never
     * existed on phasea; every live scan parsed zero rows). Rows are returned
     * verbatim (string UUIDs included); mapping onto host knowledge rows is
     * the caller's concern (`KnowledgeService::detectAllConflictsRemote`).
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function detectKnowledgeConflicts(array $context, string $operationId): array
    {
        $data = $this->makeRequest('get', 'api/ai/knowledge/conflicts', $context, $operationId);

        return $this->normalizeList($data['data'] ?? []);
    }

    /**
     * Detect knowledge gaps (questions the AI couldn't answer) via phasea.
     *
     * Same paginator envelope as `detectKnowledgeConflicts()` — the gap list
     * is under the top-level `data` key (audit D2).
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function detectKnowledgeGaps(array $context, string $operationId): array
    {
        $data = $this->makeRequest('get', 'api/ai/knowledge/gaps', $context, $operationId);

        return $this->normalizeList($data['data'] ?? []);
    }

    /**
     * Persist a knowledge entry through phasea's knowledge surface.
     *
     * phasea's `KnowledgeController::store()` validates a BODY `operation_id`
     * (`required|uuid`) — merge the forwarded operationId into the payload
     * like every other POST (audit D3); without it every push 422s.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createKnowledge(array $payload, string $operationId): array
    {
        return $this->makeRequest(
            'post',
            'api/ai/knowledge',
            array_merge($payload, ['operation_id' => $operationId]),
            $operationId,
        );
    }

    /**
     * Full-replace push of the host's prompt-override set (+ contact URL) to
     * phasea (`PUT api/ai/tenant-settings/prompt-overrides`, Phase 34 Half A /
     * DR-1: the host is authoritative for prompt overrides).
     *
     * phasea validates `overrides` as a present array (≤50 entries of
     * `{key ≤100, change_type in:append,prepend,replace_section, content
     * ≤10000, enabled bool}`), `contact_url` as nullable url ≤500, and
     * `operation_id` as uuid. PUT full-replace is naturally idempotent — the
     * caller derives the operation_id deterministically from the settings
     * version so queue retries replay the same operation.
     *
     * @param  list<array{key: string, change_type: string, content: string, enabled: bool}>  $overrides
     * @return array<string, mixed>
     */
    public function pushTenantSettings(array $overrides, ?string $contactUrl, string $operationId): array
    {
        return $this->makeRequest('put', 'api/ai/tenant-settings/prompt-overrides', [
            'overrides' => $overrides,
            'contact_url' => $contactUrl,
            'operation_id' => $operationId,
        ], $operationId);
    }

    public function detect(DetectionRequest $request): ?DetectionResult
    {
        if ($request->clientReference === null || trim($request->clientReference) === '') {
            throw new \InvalidArgumentException('client_reference is required.');
        }

        $data = $this->makeRequest('post', 'api/v1/ai/detect', [
            'text' => $request->text,
            'client_reference' => $request->clientReference,
            'operation_id' => $request->operationId,
        ], $request->operationId);

        // Same-operation_id replay (audit D4): phasea's DetectController serves
        // an existing row on resubmission — 202 {status: 'processing'} while the
        // async engine runs, or the full terminal resource at 200 (status
        // 'done'/'failed'/'submission_outcome_unknown'). Crash-recovery
        // resubmits (DetectionFlowService::resumeSubmission) must treat
        // 'processing' as accepted and surface 'done' so the flow short-circuits
        // into its normal poll completion — not fail 'invalid_response' after
        // phasea completed the work and spent budget.
        return match ($data['status'] ?? null) {
            'queued', 'processing' => DetectionResult::fromArray([
                'status' => $data['status'],
                'operation_id' => $data['operation_id'] ?? $request->operationId,
            ]),
            'done' => DetectionResult::fromArray([
                'status' => 'done',
                'operation_id' => $data['operation_id'] ?? $request->operationId,
                'score' => data_get($data, 'result.score'),
            ]),
            'failed', 'submission_outcome_unknown' => DetectionResult::fromArray([
                'status' => $data['status'],
                'operation_id' => $data['operation_id'] ?? $request->operationId,
                'error_code' => data_get($data, 'failure.error_code', 'unknown'),
            ]),
            default => throw AiInvalidResponseException::forMalformedBody(
                json_encode($data) ?: '',
                $request->operationId,
            ),
        };
    }

    public function humanize(HumanizationRequest $request): ?HumanizationResult
    {
        if ($request->clientReference === null || trim($request->clientReference) === '') {
            throw new \InvalidArgumentException('client_reference is required.');
        }

        $options = $this->sanitizeHumanizationOptions($request->options);

        $payload = [
            'text' => $request->text,
            'client_reference' => $request->clientReference,
            'operation_id' => $request->operationId,
        ];

        if ($options !== []) {
            $payload['options'] = $options;
        }

        $data = $this->makeRequest('post', 'api/v1/ai/humanize', $payload, $request->operationId);

        // Same-operation_id replay (audit D4) — mirrors detect(): phasea's
        // HumanizeController serves the existing row on resubmission (202
        // 'processing' or the 200 terminal resource). See detect() above.
        return match ($data['status'] ?? null) {
            'queued', 'processing' => HumanizationResult::fromArray([
                'status' => $data['status'],
                'operation_id' => $data['operation_id'] ?? $request->operationId,
            ]),
            'done' => HumanizationResult::fromArray([
                'status' => 'done',
                'operation_id' => $data['operation_id'] ?? $request->operationId,
                'humanized_text' => data_get($data, 'result.humanized_text'),
            ]),
            'failed', 'submission_outcome_unknown' => HumanizationResult::fromArray([
                'status' => $data['status'],
                'operation_id' => $data['operation_id'] ?? $request->operationId,
                'error_code' => data_get($data, 'failure.error_code', 'unknown'),
            ]),
            default => throw AiInvalidResponseException::forMalformedBody(
                json_encode($data) ?: '',
                $request->operationId,
            ),
        };
    }

    /**
     * Fetch one flat async-operation resource without blocking or retrying.
     *
     * @return array<string, mixed>
     */
    public function pollOperation(string $surface, string $operationId): array
    {
        if (!in_array($surface, ['detect', 'humanize', 'translate'], true)) {
            throw new \InvalidArgumentException('surface must be detect, humanize or translate.');
        }

        return $this->makeRequest(
            'get',
            "api/v1/ai/operations/{$surface}/{$operationId}",
            [],
            $operationId,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function sanitizeHumanizationOptions(array $options): array
    {
        // D-27-10/D-26-15: the vendor model is pinned inside phasea.
        unset($options['model']);

        return array_intersect_key(
            $options,
            array_flip(['readability', 'purpose', 'strength']),
        );
    }

    /**
     * Read a boolean stream-meta flag that may be absent at runtime.
     *
     * `stream_get_meta_data()`'s PHPStan stub types `timed_out` as
     * always-present, but in-memory streams (`php://temp` — notably
     * `Http::fake()`'s test doubles) omit the key at runtime. Accepting a
     * generally-typed map here reflects the real runtime shape instead of
     * indexing the narrowly-shaped array PHPStan infers at the call site.
     *
     * @param  array<string, mixed>  $meta
     */
    private function metaFlagIsTrue(array $meta, string $key): bool
    {
        return ($meta[$key] ?? null) === true;
    }

    /**
     * Parse a single SSE frame into its data payload.
     *
     * Extracts the `data:` line from the frame, JSON-decodes it, and returns
     * the decoded array. Returns null for malformed/unparseable frames
     * (silently dropped per T-08-06a).
     *
     * @return array<string, mixed>|null
     */
    private function parseSseFrame(string $frame): ?array
    {
        $dataLine = null;

        foreach (explode("\n", $frame) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $dataLine = substr($line, 6);
            } elseif ($line === 'data:') {
                $dataLine = '';
            }
        }

        if ($dataLine === null || $dataLine === '') {
            return null;
        }

        try {
            $decoded = json_decode($dataLine, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $type = $decoded['type'] ?? null;

        if (!is_string($type) || $type === '') {
            return null;
        }

        return $decoded;
    }

    /**
     * Map a single upstream event to the host's stable SSE schema
     * (chunk/tool_start/tool_complete/error/done/heartbeat + the Phase 31
     * additions: injection, action_proposal).
     *
     * Phase 31 (C31-2/C31-3/C31-4) upstream frames:
     * - tool_call frames now carry `status` (start|complete|failed) and
     *   `friendly_label`; start frames additionally carry `args`. Status-bearing
     *   frames map DIRECTLY (start → tool_start, complete/failed →
     *   tool_complete with a `status` payload field) — no synthesis needed.
     * - action_proposal frames re-emit as `action_proposal` host events.
     * - a done frame carrying `injection` emits a dedicated `injection` host
     *   event immediately BEFORE the done event.
     *
     * Legacy tool_call frames WITHOUT `status` keep the pre-31 synthesis:
     * - tool_call emits tool_start immediately and sets $pendingToolStart
     * - the NEXT event (any type) flushes tool_complete before its own event
     * - done flushes pending tool_complete before the done event
     *
     * Tool payloads carry `label` — the HOST-localized friendly label keyed by
     * tool name, falling back to phasea's `friendly_label` (C31-3).
     *
     * Unknown upstream event types are silently dropped (T-08-06a/d).
     *
     * @param  array<string, mixed>  $parsed  Decoded upstream event (has at least 'type' as string)
     * @return array<int, StreamEvent>
     */
    private function synthesizeEvents(array $parsed, bool &$pendingToolStart, string &$lastToolName): array
    {
        $events = [];
        $type = $parsed['type'] ?? '';

        if (!is_string($type) || $type === '') {
            return $events;
        }

        // Flush pending tool_complete before processing the current event
        // (any event following a LEGACY tool_call triggers tool_complete).
        if ($pendingToolStart && $type !== 'tool_call') {
            $events[] = new StreamEvent('tool_complete', ['name' => $lastToolName], 1);
            $pendingToolStart = false;
            $lastToolName = '';
        }

        switch ($type) {
            case 'chunk':
                $text = $parsed['text'] ?? '';
                $events[] = new StreamEvent('chunk', [
                    'text' => is_string($text) ? $text : '',
                ], 1);
                break;

            case 'tool_call':
                $name = $parsed['name'] ?? '';
                $name = is_string($name) ? $name : '';
                $status = $parsed['status'] ?? null;
                $friendlyLabel = $parsed['friendly_label'] ?? null;
                $label = FriendlyToolLabels::resolve($name, is_string($friendlyLabel) ? $friendlyLabel : null);

                // C31-3: status-bearing lifecycle frames map directly.
                if ($status === 'start') {
                    if ($pendingToolStart) {
                        $events[] = new StreamEvent('tool_complete', ['name' => $lastToolName], 1);
                        $pendingToolStart = false;
                        $lastToolName = '';
                    }

                    $events[] = new StreamEvent('tool_start', [
                        'name' => $name,
                        'args' => $parsed['args'] ?? [],
                        'label' => $label,
                    ], 1);
                    break;
                }

                if ($status === 'complete' || $status === 'failed') {
                    $events[] = new StreamEvent('tool_complete', [
                        'name' => $name,
                        'status' => $status,
                        'label' => $label,
                    ], 1);
                    break;
                }

                // Legacy frame without status: if there's already a pending
                // tool_start from a previous tool_call, flush tool_complete
                // for it first.
                if ($pendingToolStart) {
                    $events[] = new StreamEvent('tool_complete', ['name' => $lastToolName], 1);
                }

                $events[] = new StreamEvent('tool_start', [
                    'name' => $name,
                    'args' => $parsed['args'] ?? [],
                    'label' => $label,
                ], 1);

                $pendingToolStart = true;
                $lastToolName = $name;
                break;

            case 'action_proposal':
                // C31-4: re-emit the proposal payload (minus the frame type)
                // as a dedicated host event for the confirmation UI.
                unset($parsed['type']);
                /** @var array<string, mixed> $proposal */
                $proposal = $parsed;
                $events[] = new StreamEvent('action_proposal', $proposal, 1);
                break;

            case 'done':
                $usage = $parsed['usage'] ?? [];

                // C31-2: an injection payload on the done frame re-emits as a
                // dedicated `injection` host event BEFORE done (the frontend
                // closes the stream UI on done).
                $injection = $parsed['injection'] ?? null;

                if (is_array($injection)) {
                    /** @var array<string, mixed> $injectionPayload */
                    $injectionPayload = $injection;
                    $events[] = new StreamEvent('injection', $injectionPayload, 1);
                }

                $events[] = new StreamEvent('done', [
                    'usage' => $usage,
                ], 1);
                break;

            default:
                // Unknown upstream event type — silently dropped (T-08-06a/d).
                // Defense against service-side schema changes injecting unexpected behavior.
                break;
        }

        return $events;
    }

    /**
     * Unwrap phasea's standard `{"data": {...}}` response envelope.
     *
     * Most phasea endpoints wrap their payload under a top-level `data` key.
     * Returns the inner array, or an empty array when the key is absent or
     * not an array. Call sites that need a specific inner key simply index
     * the result: `$this->unwrapDataEnvelope($data)['analysis'] ?? ''`.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function unwrapDataEnvelope(array $data): array
    {
        $envelope = $data['data'] ?? null;

        return is_array($envelope) ? $envelope : [];
    }

    /**
     * Make an authenticated HTTP request to the phasea service and map
     * failures to typed `AiClientException` subclasses.
     *
     * `$path` is the FULL route path on phasea (e.g. `api/ai/chat` or
     * `api/v1/ai/content/generate`) — phasea splits its A6 API across two
     * prefixes (`api/ai/*` and `api/v1/ai/*`), so each call site supplies
     * its own complete, correct path (D9-8) rather than this method
     * hardcoding a single blanket prefix.
     *
     * `$operationId` is forwarded as-is (never minted here — the documented
     * `Str::uuid()` call sites are createConversation/closeConversation and,
     * since 13-04, embed/embedBatch which mint one per call) and validated
     * as a UUID before being sent, guarding against operation_id-format
     * injection (T-08-05b). An empty operationId (only `getConversations`,
     * which carries none on the contract; `getSchema()` makes no HTTP
     * request at all) skips validation.
     *
     * `$timeoutSeconds` is the caller's per-call synchronous timeout budget
     * (audit 5.1 — cs12 budgeted per feature: translation 240s, linking 120s,
     * GBP/analytics 300s). Null falls back to the global `ai.timeout`
     * default; a supplied value is clamped to the `ai.timeout_max` ceiling
     * (env `AI_SERVICE_TIMEOUT_MAX`) and floored at 1s.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function makeRequest(string $method, string $path, array $payload, string $operationId, ?int $timeoutSeconds = null): array
    {
        if ($operationId !== '' && !Str::isUuid($operationId)) {
            throw new \InvalidArgumentException('operation_id must be a valid UUID.');
        }

        $url = "{$this->baseUrl}/{$path}";

        try {
            $request = Http::withToken($this->token)
                ->withHeaders($this->clientIdentityHeaders())
                ->timeout($this->effectiveTimeout($timeoutSeconds));

            $response = match ($method) {
                'get' => $request->get($url, $payload),
                'put' => $request->put($url, $payload),
                default => $request->post($url, $payload),
            };
        } catch (ConnectionException) {
            $this->logRequestFailure($method, $path, 0, $operationId);

            throw AiUnavailableException::forStatus(0, $operationId);
        }

        // Log EVERY non-2xx phasea response once, with the correlation fields an
        // operator needs to diagnose it (status + method/path + operation_id +
        // base-URL host + token ID) — this is the single trace that turns an
        // opaque host-side "status: 401" into "token id N rejected by phasea".
        // The typed-exception ladder below is left untouched. SECURITY: never
        // the token secret or the response body (see class docblock).
        if (!$response->successful()) {
            $this->logRequestFailure($method, $path, $response->status(), $operationId);
        }

        // The two credit-exhaustion branches below are the ONLY places phaseb
        // learns the AI account is empty, so they are also where the staff-facing
        // warning is armed (Core45\AiCore\Services\AiCreditAlert). Recording it here —
        // rather than in each caller — covers every AI surface at once, including
        // the queued jobs that have no session to flash into.
        if ($response->status() === 429) {
            if ($response->json('code') === 'budget_exceeded') {
                app(AiCreditAlert::class)->record();

                throw AiBudgetExceededException::forTenant($operationId);
            }

            $retryAfter = (int) $response->header('Retry-After');

            throw AiRateLimitedException::withRetryAfter($retryAfter, $operationId);
        }

        if ($response->status() === 402) {
            app(AiCreditAlert::class)->record();

            throw AiBudgetExceededException::forTenant($operationId);
        }

        if (in_array($response->status(), [502, 503, 504], true)) {
            throw AiUnavailableException::forStatus($response->status(), $operationId);
        }

        // Remaining 4xx (429/402 already handled above) are PERMANENT client
        // errors — validation (422), auth/permission (401/403), missing
        // route/resource (404). They must not be conflated with transient
        // unavailability or jobs retry config errors that never clear
        // (16-REVIEW MJ-01).
        if ($response->clientError()) {
            // 422 {code: unsupported_provider} — the requested upstream
            // vendor is not configured on phasea (e.g. grounded search with
            // provider=perplexity and no Perplexity key). A distinct typed
            // config error (Phase 32) so callers never mistake it for a
            // payload-validation failure or degrade to a silent empty set.
            if ($response->status() === 422 && $response->json('code') === 'unsupported_provider') {
                throw AiProviderNotConfiguredException::forOperation($operationId);
            }

            // 409 {code: operation_already_completed} — a PRIOR attempt already
            // produced and metered a result under this deterministic id, so phasea
            // refuses to re-run it. This is the expected answer when a translation
            // job retries a whole object after a transient blip: the fields that
            // already succeeded land here. NOT a rejection — a distinct typed
            // exception so the caller reconciles it as "already done" against the
            // locally-persisted translation instead of reporting a failed field. A
            // plain 409 (operation_in_flight — a concurrent sibling) still falls
            // through to AiRequestRejectedException below, unchanged.
            if ($response->status() === 409 && $response->json('code') === 'operation_already_completed') {
                throw AiOperationAlreadyCompletedException::forOperation($operationId);
            }

            throw AiRequestRejectedException::forStatus($response->status(), $operationId);
        }

        if (!$response->successful()) {
            throw AiUnavailableException::forStatus($response->status(), $operationId);
        }

        // A call that phasea actually served proves credits are available again,
        // so the outstanding warning must stop firing without operator action.
        app(AiCreditAlert::class)->clear();

        $decoded = $response->json();

        if (!is_array($decoded)) {
            throw AiInvalidResponseException::forMalformedBody($response->body(), $operationId);
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($decoded as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * Emit the single structured failure trace for a non-2xx (or connection-
     * failed, status 0) phasea call. Logged to the `ai` channel at warning
     * level. SECURITY: only the token's numeric ID segment is included, never
     * the secret half or the response body (see the class docblock invariant).
     */
    private function logRequestFailure(string $method, string $path, int $status, string $operationId): void
    {
        Log::channel('ai')->warning('RemoteAiClient: phasea request failed', [
            'method' => $method,
            'path' => $path,
            'status' => $status,
            'operation_id' => $operationId,
            'base_url_host' => parse_url($this->baseUrl, PHP_URL_HOST) ?: null,
            'token_id' => $this->tokenIdForLogging(),
        ]);
    }

    /**
     * The token's numeric ID segment (the part before the "|") — a Sanctum row
     * PK that is safe to log because it cannot authenticate on its own. Returns
     * null when the token is unset or not in the expected `id|secret` shape, so
     * a misconfiguration (empty/legacy token) is itself visible in the trace.
     */
    private function tokenIdForLogging(): ?string
    {
        if ($this->token === '' || !str_contains($this->token, '|')) {
            return null;
        }

        return explode('|', $this->token, 2)[0];
    }

    /**
     * WHICH DEPLOYMENT OF THIS HOST IS CALLING (plan 48-24, finding F6).
     *
     * ─────────────────────────────────────────────────────────────────────────
     * WHY THE INVENTORY NEEDED THIS. phasea's prompt-contract inventory — the
     * POSITIVE OBSERVATION that gates retirement of the retired placeholder shape
     * — records `token_id`, tenant, disposition and PHASEA's release. Nothing
     * identified the CLIENT. Several phaseb deployments routinely share one
     * token, so a current instance could mark that token `dual_write` while an
     * idle STALE instance on the same token stayed perfectly able to wake up
     * tomorrow and send the retired shape. The gate would read "observed", and be
     * wrong. Keying on token + deployment is what makes "every ACTIVE identity
     * was observed" a question that can be answered.
     *
     * A HEADER, NOT A PARAMETER, AND THAT IS THE DESIGN DECISION. `parameters` is
     * template input: phasea validates it against the template's own rules, an
     * unknown key there is ignored rather than read, and adding a rule for it
     * would make a deployment label part of the prompt contract. It is transport
     * metadata about the caller, so it rides the transport — on EVERY phasea call
     * including the free catalog read, because an instance that only ever reads
     * the catalog is still an active identity.
     *
     * `unknown` IS A LEGITIMATE VALUE AND MUST NOT READ AS SAFE. An unlabelled
     * deployment cannot be told apart from any other unlabelled one; the
     * retirement gate treats that as UNOBSERVED, not as migrated.
     * ─────────────────────────────────────────────────────────────────────────
     *
     * @return array<string, string>
     */
    private function clientIdentityHeaders(): array
    {
        return [
            'X-Client-Release' => ((string) config('app.release', '')) ?: 'unknown',
        ];
    }

    /**
     * Clamp a caller-requested per-call timeout to the configured ceiling,
     * falling back to the global default when the caller did not ask for one.
     */
    private function effectiveTimeout(?int $requestedSeconds): int
    {
        if ($requestedSeconds === null || $requestedSeconds <= 0) {
            return $this->timeout;
        }

        return min($requestedSeconds, $this->timeoutMax);
    }

    /**
     * Extract a caller-supplied per-call timeout (int seconds) from an
     * options map, or null when absent/malformed.
     *
     * @param  array<string, mixed>  $options
     */
    private function timeoutFromOptions(array $options): ?int
    {
        $timeout = $options['timeout'] ?? null;

        if (is_int($timeout) && $timeout > 0) {
            return $timeout;
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            /** @var array<string, mixed> $row */
            $row = [];

            foreach ($item as $key => $inner) {
                $row[(string) $key] = $inner;
            }

            $normalized[] = $row;
        }

        return $normalized;
    }
}
