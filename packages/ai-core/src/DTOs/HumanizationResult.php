<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Humanization result or asynchronous operation handle.
 *
 * A queued handle carries an operation ID. Terminal results may carry the
 * humanized text, while preflight failures carry an error code.
 */
final readonly class HumanizationResult
{
    public function __construct(
        public ?int $humanizationId,
        public ?string $status,
        public ?string $operationId = null,
        public ?string $humanizedText = null,
        public ?string $errorCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $humanizationId = $data['humanization_id'] ?? null;
        $status = $data['status'] ?? null;
        $operationId = $data['operation_id'] ?? null;
        $humanizedText = $data['humanized_text'] ?? null;
        $errorCode = $data['error_code'] ?? null;

        return new self(
            humanizationId: is_int($humanizationId) ? $humanizationId : null,
            status: is_string($status) ? $status : null,
            operationId: is_string($operationId) ? $operationId : null,
            humanizedText: is_string($humanizedText) ? $humanizedText : null,
            errorCode: is_string($errorCode) ? $errorCode : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'humanization_id' => $this->humanizationId,
            'status' => $this->status,
            'operation_id' => $this->operationId,
            'humanized_text' => $this->humanizedText,
            'error_code' => $this->errorCode,
        ];
    }
}
