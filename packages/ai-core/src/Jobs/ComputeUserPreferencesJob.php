<?php

declare(strict_types=1);

namespace Core45\AiCore\Jobs;

use Illuminate\Foundation\Auth\User;
use Core45\AiCore\Services\PreferenceInferenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Background job to compute and cache user preferences.
 *
 * This job analyzes a user's conversation history and caches inferred preferences
 * to avoid expensive on-demand computation during chat requests.
 */
class ComputeUserPreferencesJob implements ShouldQueue
{
    use Queueable;

    /**
     * Cache TTL for user preferences (24 hours).
     */
    public const CACHE_TTL = 86400;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId
    ) {
        $this->onQueue('low');
    }

    /**
     * Execute the job.
     */
    public function handle(PreferenceInferenceService $service): void
    {
        $user = User::find($this->userId);

        if (!$user) {
            Log::channel('ai')->warning('ComputeUserPreferencesJob: User not found', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        try {
            // Compute preferences from conversation history
            $preferences = $service->inferPreferences($user);

            // Cache the result
            $cacheKey = self::getCacheKey($this->userId);
            Cache::put($cacheKey, $preferences->toArray(), self::CACHE_TTL);

            Log::channel('ai')->debug('ComputeUserPreferencesJob: Preferences cached', [
                'user_id' => $this->userId,
                'preference_count' => $preferences->count(),
            ]);
        } catch (\Exception $e) {
            Log::channel('ai')->error('ComputeUserPreferencesJob: Failed to compute preferences', [
                'user_id' => $this->userId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the cache key for a user's preferences.
     */
    public static function getCacheKey(int $userId): string
    {
        return "ai_user_preferences:{$userId}";
    }

    /**
     * Get cached preferences for a user.
     *
     * @return array<int, array{type: string, value: string, confidence: float, content: string}>|null
     */
    public static function getCachedPreferences(int $userId): ?array
    {
        return Cache::get(self::getCacheKey($userId));
    }

    /**
     * Clear cached preferences for a user.
     */
    public static function clearCachedPreferences(int $userId): void
    {
        Cache::forget(self::getCacheKey($userId));
    }

    /**
     * Check if preferences are cached for a user.
     */
    public static function hasCachedPreferences(int $userId): bool
    {
        return Cache::has(self::getCacheKey($userId));
    }
}
