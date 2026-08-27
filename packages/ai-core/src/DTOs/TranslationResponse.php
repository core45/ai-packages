<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Response DTO for `AiClient::translate()`.
 *
 * phasea's translate endpoint is asynchronous-only: it always returns
 * `202 {"status": "queued", "operation_id": "..."}` and never a translated
 * text (docs/ai-contract.md §4). The result is delivered via a second,
 * independent HTTP call (`ContentWriteBack::writeTranslation()` to phaseb's
 * bridge — Phase 14 scope), so `translatedText` is always null here; there
 * is no phasea-side polling/status endpoint to query.
 *
 * IDs + plain data only — never returns an Eloquent model instance.
 */
final readonly class TranslationResponse
{
    public function __construct(
        public ?string $translatedText,
        public string $status,
        public ?string $operationId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $status = $data['status'] ?? '';
        $operationId = $data['operation_id'] ?? null;

        return new self(
            translatedText: null,
            status: is_string($status) ? $status : '',
            operationId: is_string($operationId) ? $operationId : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'translated_text' => $this->translatedText,
            'status' => $this->status,
            'operation_id' => $this->operationId,
        ];
    }
}
