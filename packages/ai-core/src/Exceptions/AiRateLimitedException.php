<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

/**
 * Thrown when the remote AI service responds with a 429 rate-limit status.
 */
final class AiRateLimitedException extends AiClientException
{
    private function __construct(
        string $message,
        ?string $operationId,
        public readonly int $retryAfter,
    ) {
        parent::__construct($message, $operationId);
    }

    public static function withRetryAfter(int $retryAfterSeconds, string $operationId): self
    {
        return new self(
            "AI service rate limited (retry after: {$retryAfterSeconds}s)",
            $operationId,
            $retryAfterSeconds,
        );
    }
}
