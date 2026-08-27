<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Detection result or asynchronous operation handle.
 *
 * A queued handle carries an operation ID with no score or local record ID.
 * A terminal preflight rejection carries status `failed` and an error code.
 */
final readonly class DetectionResult
{
    public function __construct(
        public ?float $score,
        public ?string $status,
        public ?int $detectionId,
        public ?string $operationId = null,
        public ?string $errorCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $score = $data['score'] ?? null;
        $status = $data['status'] ?? null;
        $detectionId = $data['detection_id'] ?? null;
        $operationId = $data['operation_id'] ?? null;
        $errorCode = $data['error_code'] ?? null;

        return new self(
            score: is_numeric($score) ? (float) $score : null,
            status: is_string($status) ? $status : null,
            detectionId: is_int($detectionId) ? $detectionId : null,
            operationId: is_string($operationId) ? $operationId : null,
            errorCode: is_string($errorCode) ? $errorCode : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'status' => $this->status,
            'detection_id' => $this->detectionId,
            'operation_id' => $this->operationId,
            'error_code' => $this->errorCode,
        ];
    }
}
