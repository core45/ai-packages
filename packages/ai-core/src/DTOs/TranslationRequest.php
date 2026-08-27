<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Request DTO for `AiClient::translate()`.
 *
 * phasea's `POST /api/v1/ai/content/translate` requires the host-native
 * `content_id`, `content_type` (in:product,post,category), `source_content`,
 * `source_locale`, `target_locale`, and `operation_id`. Carrying the native
 * identity and source text makes the async contract independent of an
 * already-completed content-sync mapping.
 *
 * Zero real callers exist post-13-05: the only production caller
 * (`TranslatePostToLocale::translatePostFaqs()`) was rerouted onto
 * `completeText()` with `AiSurface::BlogTopics`; this contract shape is
 * retained for CONTRACT-04 completeness and future content-level translation.
 *
 * IDs + plain data only — never accepts an Eloquent model instance.
 */
final readonly class TranslationRequest
{
    public function __construct(
        public string $contentId,
        public string $contentType,
        public string $sourceLocale,
        public string $targetLocale,
        public string $sourceContent,
        public string $operationId,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $contentId = $data['content_id'] ?? '';
        $contentType = $data['content_type'] ?? '';
        $sourceLocale = $data['source_locale'] ?? '';
        $targetLocale = $data['target_locale'] ?? '';
        $sourceContent = $data['source_content'] ?? '';
        $operationId = $data['operation_id'] ?? '';

        return new self(
            contentId: is_string($contentId) ? $contentId : '',
            contentType: is_string($contentType) ? $contentType : '',
            sourceLocale: is_string($sourceLocale) ? $sourceLocale : '',
            targetLocale: is_string($targetLocale) ? $targetLocale : '',
            sourceContent: is_string($sourceContent) ? $sourceContent : '',
            operationId: is_string($operationId) ? $operationId : '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content_id' => $this->contentId,
            'content_type' => $this->contentType,
            'source_locale' => $this->sourceLocale,
            'target_locale' => $this->targetLocale,
            'source_content' => $this->sourceContent,
            'operation_id' => $this->operationId,
        ];
    }
}
