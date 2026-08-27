<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Install-wide "the AI account is out of credits" signal, surfaced to staff as
 * a blocking SweetAlert on top of (never instead of) the existing database
 * notification.
 *
 * WHY A CACHE FLAG RATHER THAN A DIRECT `alert()` CALL: credit exhaustion is
 * detected inside `RemoteAiClient`, which runs in BOTH an HTTP request and a
 * queued worker. A worker has no session, so flashing there would silently go
 * nowhere — and the queued path (`RefreshSiteProfileJob`, the `Translate*`
 * jobs) is precisely where operators hit this. Recording the condition in the
 * shared cache instead lets `WarnOnAiCreditExhaustion` raise the alert on the
 * next admin page load, whichever context detected it.
 *
 * phaseb is a SINGLE phasea tenant, so exhaustion is an install-wide condition
 * rather than a per-user one — hence one flag, shown once per staff session per
 * episode (the recorded-at timestamp is the episode identity: a later
 * exhaustion re-alerts someone who already dismissed an earlier one).
 */
class AiCreditAlert
{
    /** Shared between web and queue workers — both must see the same flag. */
    private const CACHE_KEY = 'ai:credits_exhausted_at';

    /** Per-session marker of the last episode this user was already shown. */
    private const SESSION_KEY = 'ai_credits_alert_shown_for';

    private const TTL_HOURS = 24;

    /**
     * Record that phasea refused a call for want of credits/budget.
     *
     * Idempotent per episode: an existing flag is NOT overwritten, so a burst of
     * failing jobs does not keep resetting the episode identity and re-alerting
     * staff who already dismissed it.
     */
    public function record(): void
    {
        if (Cache::has(self::CACHE_KEY)) {
            return;
        }

        Cache::put(self::CACHE_KEY, now()->timestamp, now()->addHours(self::TTL_HOURS));
    }

    /**
     * Clear the signal — called after any successful phasea call, which proves
     * credits are available again.
     */
    public function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function isExhausted(): bool
    {
        return Cache::has(self::CACHE_KEY);
    }

    /**
     * Raise the SweetAlert for the current staff session when an unseen
     * exhaustion episode is outstanding. Safe to call on every admin request.
     */
    public function flashPending(): void
    {
        $recordedAt = Cache::get(self::CACHE_KEY);

        if (!is_int($recordedAt)) {
            return;
        }

        if (session(self::SESSION_KEY) === $recordedAt) {
            return;
        }

        session([self::SESSION_KEY => $recordedAt]);

        // `alert()` is a global from `realrashid/sweet-alert`, which this
        // package does not require — it is the consuming app's choice of
        // notification UI, not the domain's. A global function is host coupling
        // that no import-based check can see, so it survived every gate here and
        // would have fataled with "call to undefined function" in any app
        // without that package installed.
        //
        // Guarded rather than removed: where SweetAlert is present the operator
        // still gets the blocking modal this class exists to raise. Where it is
        // not, the condition is logged instead of taking down the request. The
        // database notification described above is sent either way, so the
        // signal is never lost outright.
        if (!function_exists('alert')) {
            Log::warning('AI credits exhausted; SweetAlert is not installed so no modal was raised.', [
                'recorded_at' => $recordedAt,
            ]);

            return;
        }

        alert()->html(
            __('You have run out of AI credits'),
            $this->message(),
            'error',
        )->persistent(true, true);
    }

    /**
     * The alert body. Escaped because it is injected as raw HTML into the
     * SweetAlert modal; the refill link is omitted entirely when unconfigured
     * so the modal never renders a dead anchor.
     */
    private function message(): string
    {
        $message = e(__('AI features stay paused until your account is refilled.'));

        $url = config('ai.credits_refill_url');

        if (!is_string($url) || trim($url) === '') {
            return $message;
        }

        return $message
            .'<br><br><a href="'.e($url).'" target="_blank" rel="noopener noreferrer">'
            .e(__('Refill your account'))
            .'</a>';
    }
}
