<?php

declare(strict_types=1);

namespace Core45\AiCore\Jobs\AiOperations;

use Core45\AiCore\Models\AiDetection;
use Core45\AiCore\Services\Operations\DetectionPollService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PollAiDetectionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    public function __construct(public int $detectionId)
    {
        $this->onQueue((string) config('ai.assistant.ai_detection.queue', 'ai-bulk'));
    }

    public function handle(): void
    {
        app(DetectionPollService::class)->poll($this->detectionId);
    }

    public function failed(Throwable $exception): void
    {
        AiDetection::query()
            ->whereKey($this->detectionId)
            ->where('status', 'pending')
            ->increment('poll_failures', 1, ['last_poll_failed_at' => now()]);

        Log::error('AI detection poll job failed', [
            'detection_id' => $this->detectionId,
            'exception_class' => $exception::class,
        ]);
    }
}
