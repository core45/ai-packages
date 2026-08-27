<?php

declare(strict_types=1);

namespace Core45\AiCore\Exceptions;

use RuntimeException;

/**
 * Thrown by `ContentSyncEmitter` when a content-sync push to phasea receives
 * a non-2xx response, so that the caller -- `ContentSyncPushJob`, a queued
 * job -- gets real retry semantics via $tries/$backoff (SYNC-01). Never
 * thrown from the synchronous `ContentSyncObserver::saved()`/`deleted()`
 * dispatch path. Missing configuration also fails loudly inside the queued
 * job so an unattempted push can never be recorded as successful.
 *
 * Message templates MUST NOT include bearer tokens, HMAC secrets, or
 * signature values -- status codes and content identity only.
 */
final class ContentSyncPushFailedException extends RuntimeException
{
    public static function forNonSuccessfulResponse(int $status, string $contentType, string $contentId): self
    {
        return new self("Content-sync push failed with status {$status} for {$contentType}:{$contentId}");
    }

    /**
     * @param  list<string>  $missingKeys
     */
    public static function forMissingConfiguration(array $missingKeys, string $contentType, string $contentId): self
    {
        return new self(sprintf(
            'Content-sync push is not configured for %s:%s; missing %s',
            $contentType,
            $contentId,
            implode(', ', $missingKeys),
        ));
    }
}
