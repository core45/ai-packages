<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

/**
 * Thrown when the remote AI service returns a malformed response body.
 */
final class AiInvalidResponseException extends AiClientException
{
    /**
     * The raw response body is NEVER included in the message — only its byte
     * length — per T-08-02 (Information Disclosure): a malformed body could
     * itself echo back request headers/tokens from an upstream proxy error
     * page, so it must never be interpolated into a logged exception message.
     */
    public static function forMalformedBody(string $body, string $operationId): self
    {
        $byteCount = strlen($body);

        return new self("AI service returned a malformed response body ({$byteCount} bytes)", $operationId);
    }

    /**
     * Thrown when `generateImageFromTemplate()`'s secondary byte-fetch of phasea's
     * returned `data.url` fails (connection error or non-2xx). Only the
     * URL's host is included — never the full URL (which may carry query
     * params) and never the response body (T-13-11).
     */
    public static function forUnfetchableImageUrl(string $urlHost, string $operationId): self
    {
        return new self("AI service returned an unfetchable image URL (host: {$urlHost})", $operationId);
    }
}
