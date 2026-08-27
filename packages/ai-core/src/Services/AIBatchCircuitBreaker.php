<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Models\AIContentBatch;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AIBatchCircuitBreaker
{
    public function recordExecution(AIContentBatch $batch): void
    {
        $key = $this->cacheKey($batch);
        $ttl = now()->addHours(2);

        Cache::increment($key);

        if (Cache::get($key) === 1) {
            Cache::put($key, 1, $ttl);
        }
    }

    public function hasTripped(AIContentBatch $batch): bool
    {
        $count = (int) Cache::get($this->cacheKey($batch), 0);

        return $count >= $this->maxExecutions($batch);
    }

    public function tripAndAbort(AIContentBatch $batch): void
    {
        Log::critical('AIBatchCircuitBreaker: tripped', [
            'batch_id' => $batch->id,
            'executions' => Cache::get($this->cacheKey($batch), 0),
            'max' => $this->maxExecutions($batch),
            'total_items' => $batch->total_items,
        ]);

        $batch->failRemainingItems(__('Batch aborted: execution count exceeded safety limit.'));
    }

    public function reset(AIContentBatch $batch): void
    {
        Cache::forget($this->cacheKey($batch));
    }

    private function cacheKey(AIContentBatch $batch): string
    {
        return "ai_batch_circuit:{$batch->id}";
    }

    private function maxExecutions(AIContentBatch $batch): int
    {
        $multiplier = (int) config('ai.assistant.circuit_breaker.multiplier', 4);
        $minimum = (int) config('ai.assistant.circuit_breaker.min_executions', 25);

        return max($minimum, $batch->total_items * $multiplier);
    }
}
