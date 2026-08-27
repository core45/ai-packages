<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Request DTO for `AiClient::chat()`.
 *
 * IDs + plain data only — never accepts an Eloquent model instance.
 *
 * `conversationId` is the HOST-native conversation ID (stringified integer).
 * `RemoteAiClient` always transmits it as phasea's `host_conversation_id`
 * field — in both `chat()` and `streamChat()` — because phasea reserves its
 * `conversation_id` request field for phasea-internal UUIDs the host never
 * mints (docs/ai-contract.md §1).
 *
 * Phase 31 (C31-1): `modelType`, `modelId`, `recordContext` and
 * `fieldReference` carry the admin panel's record context to phasea, which
 * folds them into the agent system prompt. `modelType` may be a host model
 * CLASS here — `RemoteAiClient` maps it onto the neutral slug phasea expects
 * (`post|product|category|webinar|…`) at transmission time. `recordContext`
 * is clamped to phasea's 8KB serialized ceiling by `RemoteAiClient` before
 * being sent.
 */
final readonly class ChatRequest
{
    /**
     * @param  array<string, mixed>|null  $recordContext
     */
    public function __construct(
        public string $message,
        public ?string $conversationId,
        public ?string $modelType,
        public ?int $modelId,
        public string $operationId,
        public ?string $hostUserId = null,
        public ?array $recordContext = null,
        public ?string $fieldReference = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $message = $data['message'] ?? '';
        $conversationId = $data['conversation_id'] ?? null;
        $modelType = $data['model_type'] ?? null;
        $modelId = $data['model_id'] ?? null;
        $operationId = $data['operation_id'] ?? '';
        $hostUserId = $data['host_user_id'] ?? null;
        $recordContext = $data['record_context'] ?? null;
        $fieldReference = $data['field_reference'] ?? null;

        return new self(
            message: is_string($message) ? $message : '',
            conversationId: is_string($conversationId) ? $conversationId : null,
            modelType: is_string($modelType) ? $modelType : null,
            modelId: is_int($modelId) ? $modelId : null,
            operationId: is_string($operationId) ? $operationId : '',
            hostUserId: is_string($hostUserId) ? $hostUserId : null,
            recordContext: is_array($recordContext) ? $recordContext : null,
            fieldReference: is_string($fieldReference) ? $fieldReference : null,
        );
    }

    /**
     * `conversationId` is emitted as `host_conversation_id` — never as
     * `conversation_id`, which phasea validates as one of its own UUIDs.
     *
     * `hostUserId` (IN-07) is emitted as `host_user_id`, mirroring phasea's
     * `ChatController::run()`/`stream()` nullable `host_user_id` validation
     * rule — omitted from the payload entirely when null (RemoteAiClient
     * call sites only add the key when non-null).
     *
     * The C31-1 fields are NOT emitted here — `RemoteAiClient` owns their
     * wire mapping (neutral-slug model_type + 8KB record_context clamp).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'host_conversation_id' => $this->conversationId,
            'model_type' => $this->modelType,
            'model_id' => $this->modelId,
            'operation_id' => $this->operationId,
            'host_user_id' => $this->hostUserId,
            'record_context' => $this->recordContext,
            'field_reference' => $this->fieldReference,
        ];
    }
}
