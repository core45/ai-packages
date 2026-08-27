<?php

declare(strict_types=1);

namespace Core45\AiCore\Jobs;

use Core45\AiCore\Models\AILearnedKnowledge;
use Core45\AiCore\Services\AIImprovementApplier;
use Core45\AiCore\Services\RemoteAiClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;

/**
 * Queued push of a host-curated knowledge entry to phasea's knowledge store
 * (`POST api/ai/knowledge`, Phase 34 Half B / audit B3 — the feedback Apply
 * loop's knowledge writes must reach phasea, not just the host tables).
 *
 * Dispatched — guarded, AFTER the apply transaction commits — by
 * AIImprovementApplier when a knowledge improvement is applied. Apply NEVER
 * fails on sync failure: the dispatch (and, on a sync queue, the inline run)
 * is wrapped in a swallow-and-log guard; this job's own $tries/$backoff
 * handle transient phasea unavailability on real queue drivers.
 *
 * The operation_id is minted ONCE at dispatch time and serialized with the
 * job, so queue retries replay the same operation against phasea's
 * idempotency layer instead of creating duplicates (the D3 fix landed in
 * Wave 1: RemoteAiClient::createKnowledge merges it into the body).
 */
class PushLearnedKnowledgeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    public string $operationId;

    public function __construct(
        public int $knowledgeId,
    ) {
        $this->operationId = (string) Str::uuid();
    }

    public function handle(RemoteAiClient $client): void
    {
        $knowledge = AILearnedKnowledge::find($this->knowledgeId);

        // Deleted or disabled since dispatch — nothing to project.
        if ($knowledge === null || !$knowledge->enabled) {
            return;
        }

        $client->createKnowledge([
            'category' => mb_substr($knowledge->type->value, 0, 100),
            'topic' => mb_substr($knowledge->title, 0, 255),
            'content' => $knowledge->content,
            'source' => 'phaseb:feedback_apply',
        ], $this->operationId);
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['ai-knowledge-push', 'knowledge:'.$this->knowledgeId];
    }
}
