<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Request DTO for an asynchronous humanization submission.
 *
 * Host model and field identifiers remain local. Remote submission uses only
 * the text, allowed options, durable operation ID, and opaque client reference.
 */
final readonly class HumanizationRequest
{
    /**
     * @param  class-string  $modelType
     * @param  array{readability?: string, purpose?: string, strength?: string}  $options
     */
    public function __construct(
        public string $modelType,
        public int $modelId,
        public string $locale,
        public string $field,
        public string $text,
        public array $options,
        public string $operationId,
        public ?string $clientReference = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $modelType = $data['model_type'] ?? '';
        $modelId = $data['model_id'] ?? 0;
        $locale = $data['locale'] ?? '';
        $field = $data['field'] ?? '';
        $text = $data['text'] ?? '';
        $options = $data['options'] ?? [];
        $operationId = $data['operation_id'] ?? '';
        $clientReference = $data['client_reference'] ?? null;

        /** @var class-string $safeModelType */
        $safeModelType = is_string($modelType) ? $modelType : '';

        return new self(
            modelType: $safeModelType,
            modelId: is_int($modelId) ? $modelId : 0,
            locale: is_string($locale) ? $locale : '',
            field: is_string($field) ? $field : '',
            text: is_string($text) ? $text : '',
            options: self::normalizeOptions($options),
            operationId: is_string($operationId) ? $operationId : '',
            clientReference: is_string($clientReference) ? $clientReference : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'model_type' => $this->modelType,
            'model_id' => $this->modelId,
            'locale' => $this->locale,
            'field' => $this->field,
            'text' => $this->text,
            'options' => $this->options,
            'operation_id' => $this->operationId,
            'client_reference' => $this->clientReference,
        ];
    }

    /**
     * @return array{readability?: string, purpose?: string, strength?: string}
     */
    private static function normalizeOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        /** @var array{readability?: string, purpose?: string, strength?: string} $normalized */
        $normalized = [];

        if (isset($options['readability']) && is_string($options['readability'])) {
            $normalized['readability'] = $options['readability'];
        }

        if (isset($options['purpose']) && is_string($options['purpose'])) {
            $normalized['purpose'] = $options['purpose'];
        }

        if (isset($options['strength']) && is_string($options['strength'])) {
            $normalized['strength'] = $options['strength'];
        }

        return $normalized;
    }
}
