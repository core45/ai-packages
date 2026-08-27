<?php

declare(strict_types=1);

namespace Core45\AiCore\Services\Operations;

use Core45\AiCore\Jobs\AiOperations\PollAiDetectionJob;
use Core45\AiCore\Jobs\AiOperations\PollAiHumanizationJob;
use Core45\AiCore\Models\AiDetection;
use Core45\AiCore\Models\AiHumanization;
use Illuminate\Database\Eloquent\Builder;

final class AiOperationRecoveryService
{
    /**
     * @return array{detections: int, humanizations: int}
     */
    public function recover(): array
    {
        $recoveredDetections = 0;
        $recoveredHumanizations = 0;

        AiDetection::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->each(function (AiDetection $detection) use (&$recoveredDetections): void {
                if ($detection->submission_state === 'creating') {
                    $recoveryDelay = (int) config('ai.assistant.ai_detection.submission_recovery_seconds', 30);

                    if ($detection->updated_at->isBefore(now()->subSeconds(max($recoveryDelay, 1)))) {
                        app(DetectionFlowService::class)->resumeSubmission($detection->id);
                        $recoveredDetections++;
                    }

                    return;
                }

                if ($detection->poll_give_up_at?->isPast() === true) {
                    $detection->update([
                        'status' => 'failed',
                        'error_code' => 'poll_give_up',
                        'active_intent_key' => null,
                    ]);
                    app(AiOperationContinuationService::class)->release($detection->refresh());

                    return;
                }

                $interval = (int) config('ai.assistant.ai_detection.poll_interval_seconds', 2);

                if ($detection->updated_at->isAfter(now()->subSeconds(max($interval, 1)))) {
                    return;
                }

                PollAiDetectionJob::dispatch($detection->id)
                    ->onQueue((string) config('ai.assistant.ai_detection.queue', 'ai-bulk'));
                $recoveredDetections++;
            });

        AiHumanization::query()
            ->where('status', 'pending')
            ->orderBy('id')
            ->each(function (AiHumanization $humanization) use (&$recoveredHumanizations): void {
                if ($humanization->submission_state === 'creating') {
                    $recoveryDelay = (int) config('ai.assistant.ai_humanization.submission_recovery_seconds', 30);

                    if ($humanization->updated_at->isBefore(now()->subSeconds(max($recoveryDelay, 1)))) {
                        app(HumanizationFlowService::class)->resumeSubmission($humanization->id);
                        $recoveredHumanizations++;
                    }

                    return;
                }

                if ($humanization->poll_give_up_at?->isPast() === true) {
                    $humanization->update([
                        'status' => 'failed',
                        'error_code' => 'poll_give_up',
                        'active_intent_key' => null,
                    ]);
                    app(AiOperationContinuationService::class)->release($humanization->refresh());

                    return;
                }

                $interval = (int) config('ai.assistant.ai_humanization.poll_interval_seconds', 5);

                if ($humanization->updated_at->isAfter(now()->subSeconds(max($interval, 1)))) {
                    return;
                }

                PollAiHumanizationJob::dispatch($humanization->id)
                    ->onQueue((string) config('ai.assistant.ai_humanization.queue', 'ai-bulk'));
                $recoveredHumanizations++;
            });

        AiDetection::query()
            ->whereIn('status', ['done', 'failed'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('active_intent_key')
                    ->orWhereHas('continuations', fn (Builder $query) => $query->whereNull('released_at'));
            })
            ->each(function (AiDetection $detection): void {
                if ($detection->isDone()) {
                    app(DetectionPollService::class)->poll($detection->id);

                    return;
                }

                app(AiOperationContinuationService::class)->release($detection);
            });

        AiHumanization::query()
            ->whereIn('status', ['done', 'failed'])
            ->where(function (Builder $query): void {
                $query->whereNotNull('active_intent_key')
                    ->orWhere(function (Builder $query): void {
                        $query->where('status', 'done')->whereNull('written_back_at');
                    })
                    ->orWhereHas('continuations', fn (Builder $query) => $query->whereNull('released_at'));
            })
            ->each(fn (AiHumanization $humanization) => app(HumanizationPollService::class)->poll($humanization->id));

        app(AiOperationContinuationService::class)->dispatchReleased();

        return [
            'detections' => $recoveredDetections,
            'humanizations' => $recoveredHumanizations,
        ];
    }
}
