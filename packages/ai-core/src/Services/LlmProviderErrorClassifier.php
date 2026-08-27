<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Exceptions\AiRateLimitedException;
use Core45\AiCore\Exceptions\AiRequestRejectedException;
use Core45\AiCore\Exceptions\AiUnavailableException;
use Throwable;

/**
 * Single source of truth for "this provider can't serve the request right now."
 *
 * Historically shared by two blog AI failover mechanisms so their
 * classification could not drift: BlogPipeline's client-side multi-provider
 * fallback chain (deleted in Plan 16-04 -- no analog against phasea's single
 * opaque endpoint) and BlogTopics' `GeneratesResearchText` (single secondary
 * provider, owned by Plan 16-05). Still consumed by `GeneratesResearchText`
 * and by `ReleaseOnProviderRateLimit` (job-level transient-retry
 * classification).
 */
final class LlmProviderErrorClassifier
{
    /**
     * Message fragments (lower-cased) that mean the provider is unusable —
     * transient outages, a missing model, or the account being unusable
     * (billing/credit/quota exhaustion, auth/permission failures).
     *
     * @var list<string>
     */
    private const array UNUSABLE_SIGNALS = [
        'timeout',
        'not_found',
        'overloaded',
        'credit balance',
        'billing',
        'quota',
        'payment',
        'authentication',
        'api key',
        'permission denied',
    ];

    /**
     * HTTP statuses that mean the provider cannot serve this request: transient
     * (5xx / 429), a missing model (404), or the account being unusable on this
     * provider for auth/permission reasons (401 / 403).
     *
     * Note: billing/credit exhaustion is deliberately NOT keyed on 400 —
     * Anthropic returns it as a plain 400, and blanket-failing-over on every
     * 400 would waste the whole chain on a genuinely malformed request. Billing
     * is matched by message instead.
     *
     * @var list<int>
     */
    private const array UNUSABLE_STATUS_CODES = [401, 403, 404, 429, 500, 502, 503, 529];

    /**
     * Whether the error means "try a different provider" rather than
     * "fail the operation."
     */
    public static function isProviderUnusable(Throwable $e): bool
    {
        // AiUnavailableException is transient-only vocabulary since 16-REVIEW
        // MJ-01 (connection failure / 5xx); permanent 4xx rejections arrive as
        // AiRequestRejectedException carrying a typed status. 401/403/404 mean
        // THIS provider is unusable (auth/permission/missing model — matches
        // UNUSABLE_STATUS_CODES); 400/422 are genuinely malformed requests
        // that no provider fallback can fix.
        if ($e instanceof AiRequestRejectedException) {
            return in_array($e->status, self::UNUSABLE_STATUS_CODES, true);
        }

        if ($e instanceof AiRateLimitedException || $e instanceof AiUnavailableException) {
            return true;
        }

        if (in_array($e->getCode(), self::UNUSABLE_STATUS_CODES, true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        foreach (self::UNUSABLE_SIGNALS as $needle) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the error is *transient* — i.e. waiting and retrying the same
     * request may succeed: rate limits, server overload, or 5xx.
     *
     * This is the narrower subset used for job-level "release and back off"
     * decisions. It deliberately EXCLUDES billing/credit/quota exhaustion,
     * auth/permission failures, and missing models (404): those do not clear by
     * waiting — they need a different provider (see {@see isProviderUnusable})
     * or human action.
     */
    public static function isTransient(Throwable $e): bool
    {
        // Permanent 4xx rejection (422 validation, 401/403 auth, 404 missing
        // resource) — waiting cannot clear it (16-REVIEW MJ-01).
        if ($e instanceof AiRequestRejectedException) {
            return false;
        }

        // Since 16-REVIEW MJ-01, AiUnavailableException is thrown ONLY for
        // connection failures and 5xx — genuinely transient by construction.
        if ($e instanceof AiRateLimitedException || $e instanceof AiUnavailableException) {
            return true;
        }

        if (in_array($e->getCode(), [429, 500, 502, 503, 529], true)) {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'rate limit')
            || str_contains($message, 'rate_limit')
            || str_contains($message, 'overloaded')
            || str_contains($message, 'timeout');
    }
}
