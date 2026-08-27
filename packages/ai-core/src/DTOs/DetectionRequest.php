<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Request DTO for an asynchronous detect submission.
 *
 * Host model identifiers remain local. Remote submission uses only the text,
 * durable operation ID, and opaque client reference.
 */
final readonly class DetectionRequest
{
    /**
     * @param  class-string  $modelType
     */
    public function __construct(
        public string $modelType,
        public int $modelId,
        public string $locale,
        public string $text,
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
        $text = $data['text'] ?? '';
        $operationId = $data['operation_id'] ?? '';
        $clientReference = $data['client_reference'] ?? null;

        /** @var class-string $safeModelType */
        $safeModelType = is_string($modelType) ? $modelType : '';

        return new self(
            modelType: $safeModelType,
            modelId: is_int($modelId) ? $modelId : 0,
            locale: is_string($locale) ? $locale : '',
            text: is_string($text) ? $text : '',
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
            'text' => $this->text,
            'operation_id' => $this->operationId,
            'client_reference' => $this->clientReference,
        ];
    }
}
