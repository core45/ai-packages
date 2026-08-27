<?php

declare(strict_types=1);

namespace Core45\AiCore\Jobs\AiOperations;

use Core45\AiCore\Models\AiOperationContinuation;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResumeAiOperationChainJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(public int $continuationId)
    {
        $this->onQueue('ai-bulk');
    }

    public function uniqueId(): string
    {
        return 'ai-operation-continuation:'.$this->continuationId;
    }

    public function handle(): void
    {
        AiOperationContinuation::query()
            ->whereKey($this->continuationId)
            ->whereNull('dispatched_at')
            ->update(['dispatched_at' => now()]);
    }

    public function failed(Throwable $throwable): void
    {
        Log::error('AI operation continuation wrapper failed', [
            'continuation_id' => $this->continuationId,
            'exception_class' => $throwable::class,
        ]);
    }
}
