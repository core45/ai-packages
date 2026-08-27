<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

/**
 * Thrown when an SSE stream from the remote AI service is interrupted before
 * completion.
 */
final class AiStreamAbortedException extends AiClientException
{
    public static function afterBytes(int $bytesReceived, string $operationId): self
    {
        return new self("AI service stream aborted after {$bytesReceived} bytes", $operationId);
    }
}
