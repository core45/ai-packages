<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Request DTO for `AiClient::generateContent()`/`generateSEO()`.
 *
 * IDs + plain data only — never accepts an Eloquent model instance.
 *
 * `objectType`/`objectId`/`fieldName` (additive, 13-04) are phasea's
 * content-generation contract fields (docs/ai-contract.md §2). When null,
 * `RemoteAiClient::generateContent()` derives them from the legacy `context`
 * map (`model_type`/`model_id`/`field`) so existing callers keep working.
 * `toArray()` intentionally excludes them — generateSEO() still consumes the
 * legacy shape until Plan 13-05 moves it onto its own DTO.
 */
final readonly class ContentRequest
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $type,
        public array $context,
        public string $locale,
        public string $operationId,
        public ?string $objectType = null,
        public ?string $objectId = null,
        public ?string $fieldName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? '';
        $context = $data['context'] ?? [];
        $locale = $data['locale'] ?? '';
        $operationId = $data['operation_id'] ?? '';
        $objectType = $data['object_type'] ?? null;
        $objectId = $data['object_id'] ?? null;
        $fieldName = $data['field_name'] ?? null;

        return new self(
            type: is_string($type) ? $type : '',
            context: self::normalizeMap($context),
            locale: is_string($locale) ? $locale : '',
            operationId: is_string($operationId) ? $operationId : '',
            objectType: is_string($objectType) ? $objectType : null,
            objectId: is_string($objectId) ? $objectId : null,
            fieldName: is_string($fieldName) ? $fieldName : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'context' => $this->context,
            'locale' => $this->locale,
            'operation_id' => $this->operationId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $item;
        }

        return $normalized;
    }
}
