<?php

declare(strict_types=1);

namespace Core45\AiCore\Jobs\AiOperations;

use Core45\AiCore\Models\AiHumanization;
use Core45\AiCore\Services\Operations\HumanizationPollService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class PollAiHumanizationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [10, 30, 60];

    public int $timeout = 30;

    public function __construct(public int $humanizationId)
    {
        $this->onQueue((string) config('ai.assistant.ai_humanization.queue', 'ai-bulk'));
    }

    public function handle(): void
    {
        app(HumanizationPollService::class)->poll($this->humanizationId);
    }

    public function failed(Throwable $exception): void
    {
        AiHumanization::query()
            ->whereKey($this->humanizationId)
            ->where('status', 'pending')
            ->increment('poll_failures', 1, ['last_poll_failed_at' => now()]);

        Log::error('AI humanization poll job failed', [
            'humanization_id' => $this->humanizationId,
            'exception_class' => $exception::class,
        ]);
    }
}
