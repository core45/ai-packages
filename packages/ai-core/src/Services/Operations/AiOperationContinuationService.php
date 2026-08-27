<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use Core45\AiCore\Jobs\AiOperations\ResumeAiOperationChainJob;
use Core45\AiCore\Models\AiDetection;
use Core45\AiCore\Models\AiHumanization;
use Core45\AiCore\Models\AiOperationContinuation;

/**
 * `operation_type` is written and read through `getMorphClass()`, never
 * `::class`. The morph map registered by the consuming app (this app:
 * `AppServiceProvider::registerAiMorphMap()`) turns that into the stable
 * `ai_detection` / `ai_humanization` alias, so the column keeps matching
 * across namespace moves — and matches the `operation` morph relation on
 * `AiDetection`/`AiHumanization`, which Eloquent also constrains by
 * `getMorphClass()`. Using `::class` here silently desynchronised the two.
 */
final class AiOperationContinuationService
{
    public function attach(
        AiDetection|AiHumanization $operation,
        ?QueuedChainContinuation $continuation,
    ): void {
        if ($continuation === null) {
            return;
        }

        $payload = $continuation->encode();

        AiOperationContinuation::query()->createOrFirst([
            'continuation_key' => hash('sha256', implode("\0", [
                $operation->getMorphClass(),
                (string) $operation->id,
                $payload,
            ])),
        ], [
            'operation_type' => $operation->getMorphClass(),
            'operation_id' => $operation->id,
            'payload' => $payload,
        ]);
    }

    public function transfer(AiDetection $detection, AiHumanization $humanization): void
    {
        AiOperationContinuation::query()
            ->where('operation_type', $detection->getMorphClass())
            ->where('operation_id', $detection->id)
            ->whereNull('released_at')
            ->update([
                'operation_type' => $humanization->getMorphClass(),
                'operation_id' => $humanization->id,
            ]);
    }

    public function release(AiDetection|AiHumanization $operation): void
    {
        AiOperationContinuation::query()
            ->where('operation_type', $operation->getMorphClass())
            ->where('operation_id', $operation->id)
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        $operation->newQuery()
            ->whereKey($operation->id)
            ->update(['active_intent_key' => null]);

        if ($operation instanceof AiHumanization) {
            AiDetection::query()
                ->where('operation_id', $operation->operation_id)
                ->update(['active_intent_key' => null]);
        }

        $this->dispatchReleased();
    }

    public function dispatchReleased(): int
    {
        $dispatched = 0;

        AiOperationContinuation::query()
            ->whereNotNull('released_at')
            ->whereNull('dispatched_at')
            ->orderBy('id')
            ->each(function (AiOperationContinuation $record) use (&$dispatched): void {
                $continuation = QueuedChainContinuation::decode($record->payload);

                if ($continuation === null) {
                    return;
                }

                $job = new ResumeAiOperationChainJob($record->id);
                $continuation->attachTo($job);
                dispatch($job);
                $dispatched++;
            });

        return $dispatched;
    }
}
