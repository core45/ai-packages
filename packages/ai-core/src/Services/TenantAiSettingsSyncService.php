<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Models\AIPromptOverride;
use Illuminate\Support\Facades\Cache;

/**
 * Compiles and pushes the host's prompt-override set (+ contact URL) to
 * phasea (`PUT api/ai/tenant-settings/prompt-overrides`, Phase 34 Half B /
 * DR-1: the host is authoritative — every push is a FULL REPLACE of phasea's
 * stored set).
 *
 * Idempotency: the operation_id is derived DETERMINISTICALLY from the sha1 of
 * the compiled payload, so queued-job retries (and duplicate observer
 * dispatches for the same settings state) replay the identical operation
 * instead of minting new ones.
 *
 * Visibility (audit B3): the outcome of the LAST attempted push is persisted
 * in the cache (`last_synced_at` / `last_sync_error`) and surfaced on the
 * AI Prompt Overrides list page, so silent drift between the host's override
 * set and phasea's copy is detectable at a glance.
 *
 * contact_url source: the host stores its support/contact page as the
 * relative `ai.assistant.customer_assistant.contact_url` config path — it is
 * absolutized against APP_URL here because phasea validates a full URL.
 */
class TenantAiSettingsSyncService
{
    /** Cache key holding the last sync outcome (status surface). */
    public const STATUS_CACHE_KEY = 'ai:tenant-settings-sync:status';

    /** phasea's validation ceiling on the overrides list. */
    private const MAX_OVERRIDES = 50;

    public function __construct(
        private readonly RemoteAiClient $client,
    ) {}

    /**
     * Compile the full current override set and PUT it to phasea.
     *
     * Records the outcome in the status cache in BOTH directions; failures
     * re-throw so the queued job's tries/backoff retry semantics engage.
     */
    public function push(): void
    {
        $overrides = $this->compileOverrides();
        $contactUrl = $this->contactUrl();
        $operationId = $this->deterministicOperationId($overrides, $contactUrl);

        try {
            $this->client->pushTenantSettings($overrides, $contactUrl, $operationId);
        } catch (\Throwable $e) {
            $this->recordStatus(error: mb_substr($e->getMessage(), 0, 500));

            throw $e;
        }

        $this->recordStatus(error: null);
    }

    /**
     * Compile the host's AIPromptOverride rows into phasea's wire entries.
     *
     * The FULL set (enabled AND disabled — phasea drops disabled entries
     * server-side, DR-1 full-replace) in stable creation order, clamped to
     * phasea's 50-entry validation ceiling.
     *
     * @return list<array{key: string, change_type: string, content: string, enabled: bool}>
     */
    public function compileOverrides(): array
    {
        /** @var list<array{key: string, change_type: string, content: string, enabled: bool}> */
        return AIPromptOverride::query()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit(self::MAX_OVERRIDES)
            ->get()
            ->map(fn (AIPromptOverride $override): array => [
                'key' => mb_substr($override->prompt_key, 0, 100),
                'change_type' => in_array($override->change_type, ['append', 'prepend', 'replace_section'], true)
                    ? $override->change_type
                    : 'append',
                'content' => mb_substr($override->override_content, 0, 10000),
                'enabled' => $override->enabled,
            ])
            ->values()
            ->all();
    }

    /**
     * Absolute store contact URL, or null when unconfigured.
     */
    public function contactUrl(): ?string
    {
        $configured = config('ai.assistant.customer_assistant.contact_url');

        if (!is_string($configured) || trim($configured) === '') {
            return null;
        }

        $configured = trim($configured);

        if (str_starts_with($configured, 'http://') || str_starts_with($configured, 'https://')) {
            return mb_substr($configured, 0, 500);
        }

        return mb_substr(url($configured), 0, 500);
    }

    /**
     * Last sync outcome for the admin surface.
     *
     * @return array{last_synced_at: string|null, last_sync_error: string|null, last_attempt_at: string|null}
     */
    public function status(): array
    {
        $status = Cache::get(self::STATUS_CACHE_KEY);

        if (!is_array($status)) {
            return [
                'last_synced_at' => null,
                'last_sync_error' => null,
                'last_attempt_at' => null,
            ];
        }

        return [
            'last_synced_at' => is_string($status['last_synced_at'] ?? null) ? $status['last_synced_at'] : null,
            'last_sync_error' => is_string($status['last_sync_error'] ?? null) ? $status['last_sync_error'] : null,
            'last_attempt_at' => is_string($status['last_attempt_at'] ?? null) ? $status['last_attempt_at'] : null,
        ];
    }

    /**
     * Derive a stable RFC-4122-shaped UUID from the payload contents so
     * retries of the same settings version reuse one operation_id.
     *
     * @param  list<array{key: string, change_type: string, content: string, enabled: bool}>  $overrides
     */
    private function deterministicOperationId(array $overrides, ?string $contactUrl): string
    {
        $hash = sha1((string) json_encode([$overrides, $contactUrl]));

        // Format the first 32 hex chars as a UUID, pinning the version nibble
        // to 5 and the variant nibble to 8 so Str::isUuid()/uuid rules pass.
        return sprintf(
            '%s-%s-5%s-8%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            substr($hash, 17, 3),
            substr($hash, 20, 12),
        );
    }

    /**
     * Persist the last-attempt outcome for the status surface.
     */
    private function recordStatus(?string $error): void
    {
        $previous = $this->status();

        Cache::forever(self::STATUS_CACHE_KEY, [
            'last_synced_at' => $error === null ? now()->toIso8601String() : $previous['last_synced_at'],
            'last_sync_error' => $error,
            'last_attempt_at' => now()->toIso8601String(),
        ]);
    }
}
