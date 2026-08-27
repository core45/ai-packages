<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

/**
 * Thrown when the remote AI service is unreachable or returns a 5xx status.
 */
final class AiUnavailableException extends AiClientException
{
    public static function forStatus(int $status, string $operationId): self
    {
        return new self("AI service unavailable (status: {$status})", $operationId);
    }
}
