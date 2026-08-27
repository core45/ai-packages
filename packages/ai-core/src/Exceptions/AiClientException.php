<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base exception for remote AI client failures.
 *
 * Thrown by `RemoteAiClient` for failures returned by the phasea service.
 *
 * Message templates MUST NOT include bearer tokens, API keys, or Authorization
 * header values — use status codes, byte counts, and surface names only.
 */
abstract class AiClientException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $operationId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Build the exception thrown when a remote surface is unsupported.
     */
    public static function unsupportedSurface(string $surface, string $operationId): self
    {
        return AiUnsupportedSurfaceException::forSurface($surface, $operationId);
    }
}
