<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

use Throwable;

/**
 * Thrown when the remote AI service rejects the request with a permanent
 * client error (4xx other than 429/402): validation failure (422 — e.g. an
 * over-length prompt or an unwhitelisted surface), auth/permission failure
 * (401/403), or a missing route/resource (404).
 *
 * Deliberately DISTINCT from {@see AiUnavailableException} (connection
 * failures + 5xx, i.e. transient outages): a rejected request will never
 * succeed by waiting and retrying, so job middleware and error classifiers
 * must fail fast instead of releasing for retry (16-REVIEW MJ-01).
 *
 * Carries the HTTP status as a typed property — `getCode()` is hardwired to
 * 0 by the base class, so consumers branch on `$e->status`.
 */
class AiRequestRejectedException extends AiClientException
{
    public function __construct(
        string $message,
        public readonly int $status,
        ?string $operationId = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $operationId, $previous);
    }

    public static function forStatus(int $status, string $operationId): self
    {
        return new self("AI service rejected the request (status: {$status})", $status, $operationId);
    }
}
