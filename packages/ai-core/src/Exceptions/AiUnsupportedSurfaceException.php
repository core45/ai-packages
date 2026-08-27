<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

/**
 * Thrown when a surface is not supported by the resolved `AiClient`
 * implementation — specifically, the D11 detect/humanize surfaces on
 * `RemoteAiClient`, which never go remote.
 *
 * Reached via the base class's named entry point: `AiClientException::unsupportedSurface(...)`.
 * The message names the surface value only.
 */
final class AiUnsupportedSurfaceException extends AiClientException
{
    public static function forSurface(string $surface, string $operationId): self
    {
        return new self("AI surface not supported: {$surface}", $operationId);
    }
}
