<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Enums\AIImprovementStatus;
use Core45\AiCore\Enums\AIImprovementType;
use Core45\AiCore\Jobs\PushLearnedKnowledgeJob;
use Core45\AiCore\Models\AIImprovementSuggestion;
use Core45\AiCore\Models\AILearnedKnowledge;
use Core45\AiCore\Models\AIPromptOverride;
use Core45\AiCore\Notifications\AIImprovementAppliedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AIImprovementApplier
{
    /**
     * Apply an approved suggestion.
     *
     * @return array{success: bool, message: string, result?: mixed}
     */
    public function apply(AIImprovementSuggestion $suggestion): array
    {
        if ($suggestion->status !== AIImprovementStatus::Approved) {
            return [
                'success' => false,
                'message' => 'Suggestion must be approved before applying.',
            ];
        }

        $this->pendingKnowledgePushIds = [];

        try {
            $result = DB::transaction(function () use ($suggestion): array {
                $result = match ($suggestion->type) {
                    AIImprovementType::Knowledge => $this->applyKnowledgeImprovement($suggestion),
                    AIImprovementType::Prompt => $this->applyPromptImprovement($suggestion),
                    AIImprovementType::Tool => $this->handleToolImprovement($suggestion),
                };

                if ($result['success'] && $suggestion->type !== AIImprovementType::Tool) {
                    $suggestion->markAsApplied();
                    $this->notifyApplied($suggestion);
                }

                return $result;
            });

            // Phase 34 Half B (audit B3): queue the phasea knowledge pushes
            // AFTER the transaction so a dispatch/sync failure can never fail
            // the apply itself — guarded in dispatchPendingKnowledgePushes().
            $this->dispatchPendingKnowledgePushes();

            return $result;
        } catch (\Throwable $e) {
            Log::error('Failed to apply AI improvement', [
                'suggestion_id' => $suggestion->id,
                'type' => $suggestion->type->value,
                'error' => $e->getMessage(),
            ]);

            $suggestion->update(['status' => AIImprovementStatus::NeedsDevelopment]);

            return [
                'success' => false,
                'message' => 'Failed to apply improvement: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Apply a knowledge base improvement.
     *
     * @return array{success: bool, message: string, result?: AILearnedKnowledge}
     */
    protected function applyKnowledgeImprovement(AIImprovementSuggestion $suggestion): array
    {
        $changes = $suggestion->suggested_changes;

        // Update existing knowledge or create new
        if ($suggestion->target_knowledge_id) {
            $knowledge = AILearnedKnowledge::find($suggestion->target_knowledge_id);

            if (!$knowledge) {
                return [
                    'success' => false,
                    'message' => 'Target knowledge entry not found.',
                ];
            }

            // Update the knowledge entry
            $knowledge->update([
                'title' => $changes['title'] ?? $knowledge->title,
                'content' => $changes['content'] ?? $knowledge->content,
                'keywords' => $changes['keywords'] ?? $knowledge->keywords,
                'type' => $changes['type'] ?? $knowledge->type,
                'tier' => $changes['tier'] ?? $knowledge->tier,
                'audience' => $changes['audience'] ?? $knowledge->audience,
            ]);

            Log::info('Applied knowledge update', [
                'suggestion_id' => $suggestion->id,
                'knowledge_id' => $knowledge->id,
            ]);

            $knowledge->refresh();

            $this->queueKnowledgePush($knowledge);

            return [
                'success' => true,
                'message' => 'Knowledge entry updated successfully.',
                'result' => $knowledge,
            ];
        }

        // Create new knowledge entry
        /** @var AILearnedKnowledge $knowledge */
        $knowledge = AILearnedKnowledge::create([
            'title' => $changes['title'] ?? $suggestion->title,
            'content' => $changes['content'] ?? '',
            'keywords' => $changes['keywords'] ?? [],
            'type' => $changes['type'] ?? 'fact',
            'tier' => $changes['tier'] ?? 'contextual',
            'audience' => $changes['audience'] ?? 'public',
            'source_type' => 'suggested',
            'enabled' => true,
        ]);

        // Link the suggestion to the new knowledge
        $suggestion->update(['target_knowledge_id' => $knowledge->id]);

        Log::info('Created new knowledge from feedback', [
            'suggestion_id' => $suggestion->id,
            'knowledge_id' => $knowledge->id,
        ]);

        $this->queueKnowledgePush($knowledge);

        return [
            'success' => true,
            'message' => 'New knowledge entry created successfully.',
            'result' => $knowledge,
        ];
    }

    /**
     * Apply a prompt override improvement.
     *
     * @return array{success: bool, message: string, result?: AIPromptOverride}
     */
    protected function applyPromptImprovement(AIImprovementSuggestion $suggestion): array
    {
        $changes = $suggestion->suggested_changes;
        $promptKey = $suggestion->target_prompt_key ?? $changes['prompt_key'] ?? 'customer_system';

        // Check if override for this key already exists from this suggestion
        $existingOverride = AIPromptOverride::query()
            ->where('suggestion_id', $suggestion->id)
            ->first();

        if ($existingOverride) {
            $existingOverride->update([
                'override_content' => $changes['content'] ?? $changes['override_content'] ?? '',
                'change_type' => $changes['change_type'] ?? 'append',
                'enabled' => true,
                'description' => $suggestion->problem_summary,
            ]);

            Log::info('Updated prompt override', [
                'suggestion_id' => $suggestion->id,
                'override_id' => $existingOverride->id,
            ]);

            $existingOverride->refresh();

            return [
                'success' => true,
                'message' => 'Prompt override updated successfully.',
                'result' => $existingOverride,
            ];
        }

        // Create new prompt override
        /** @var AIPromptOverride $override */
        $override = AIPromptOverride::create([
            'prompt_key' => $promptKey,
            'override_content' => $changes['content'] ?? $changes['override_content'] ?? '',
            'change_type' => $changes['change_type'] ?? 'append',
            'suggestion_id' => $suggestion->id,
            'enabled' => true,
            'description' => $suggestion->problem_summary,
        ]);

        // Update the suggestion with the target prompt key if not set
        if (!$suggestion->target_prompt_key) {
            $suggestion->update(['target_prompt_key' => $promptKey]);
        }

        Log::info('Created prompt override from feedback', [
            'suggestion_id' => $suggestion->id,
            'override_id' => $override->id,
            'prompt_key' => $promptKey,
        ]);

        return [
            'success' => true,
            'message' => 'Prompt override created successfully.',
            'result' => $override,
        ];
    }

    /**
     * Knowledge rows written during the current apply() whose phasea push is
     * still pending — flushed AFTER the transaction commits.
     *
     * @var list<int>
     */
    protected array $pendingKnowledgePushIds = [];

    /**
     * Record a knowledge row for the post-commit phasea push (Phase 34
     * Half B, audit B3 — the Apply loop's knowledge writes must reach
     * phasea's knowledge store, not just the host tables).
     */
    protected function queueKnowledgePush(AILearnedKnowledge $knowledge): void
    {
        $this->pendingKnowledgePushIds[] = $knowledge->id;
    }

    /**
     * Dispatch the queued phasea knowledge pushes.
     *
     * GUARDED and post-transaction: Apply must NEVER fail on sync failure —
     * a dispatch problem (or, on a sync queue, an inline job failure) is
     * logged and swallowed; transient phasea failures are the queued job's
     * own retry concern.
     */
    protected function dispatchPendingKnowledgePushes(): void
    {
        $knowledgeIds = $this->pendingKnowledgePushIds;
        $this->pendingKnowledgePushIds = [];

        foreach ($knowledgeIds as $knowledgeId) {
            try {
                PushLearnedKnowledgeJob::dispatch($knowledgeId);
            } catch (\Throwable $e) {
                Log::warning('Failed to queue knowledge push to AI service', [
                    'knowledge_id' => $knowledgeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Handle tool improvement - these cannot be auto-applied.
     *
     * @return array{success: bool, message: string}
     */
    protected function handleToolImprovement(AIImprovementSuggestion $suggestion): array
    {
        // Tool improvements require code changes - mark for development
        $suggestion->markAsNeedsDevelopment();

        Log::info('Tool improvement marked for development', [
            'suggestion_id' => $suggestion->id,
            'tool_class' => $suggestion->target_tool_class,
        ]);

        return [
            'success' => false,
            'message' => 'Tool improvements require manual development. Marked as needs development.',
        ];
    }

    /**
     * Process all approved suggestions that can be auto-applied.
     *
     * @return array{processed: int, applied: int, failed: int}
     */
    public function processApprovedSuggestions(): array
    {
        $suggestions = AIImprovementSuggestion::query()
            ->approved()
            ->whereIn('type', [AIImprovementType::Knowledge, AIImprovementType::Prompt])
            ->get();

        $stats = [
            'processed' => 0,
            'applied' => 0,
            'failed' => 0,
        ];

        foreach ($suggestions as $suggestion) {
            $stats['processed']++;
            $result = $this->apply($suggestion);

            if ($result['success']) {
                $stats['applied']++;
            } else {
                $stats['failed']++;
            }
        }

        Log::info('Processed approved AI suggestions', $stats);

        return $stats;
    }

    /**
     * Notify relevant users about applied improvement.
     */
    protected function notifyApplied(AIImprovementSuggestion $suggestion): void
    {
        if (!config('ai.assistant.feedback_analysis.notifications.enabled', true)) {
            return;
        }

        // Notify the reviewer if they exist
        if ($suggestion->reviewer) {
            $suggestion->reviewer->notify(new AIImprovementAppliedNotification($suggestion));
        }
    }

    /**
     * Undo an applied improvement.
     *
     * @return array{success: bool, message: string}
     */
    public function undo(AIImprovementSuggestion $suggestion): array
    {
        if ($suggestion->status !== AIImprovementStatus::Applied) {
            return [
                'success' => false,
                'message' => 'Can only undo applied improvements.',
            ];
        }

        try {
            return DB::transaction(function () use ($suggestion): array {
                return match ($suggestion->type) {
                    AIImprovementType::Knowledge => $this->undoKnowledgeImprovement($suggestion),
                    AIImprovementType::Prompt => $this->undoPromptImprovement($suggestion),
                    AIImprovementType::Tool => [
                        'success' => false,
                        'message' => 'Tool improvements must be manually reverted.',
                    ],
                };
            });
        } catch (\Throwable $e) {
            Log::error('Failed to undo AI improvement', [
                'suggestion_id' => $suggestion->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to undo improvement: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Undo a knowledge improvement.
     *
     * @return array{success: bool, message: string}
     */
    protected function undoKnowledgeImprovement(AIImprovementSuggestion $suggestion): array
    {
        if ($suggestion->target_knowledge_id) {
            // Disable the knowledge entry instead of deleting
            AILearnedKnowledge::where('id', $suggestion->target_knowledge_id)
                ->update(['enabled' => false]);

            $suggestion->update([
                'status' => AIImprovementStatus::Approved,
                'applied_at' => null,
            ]);

            Log::info('Undid knowledge improvement', [
                'suggestion_id' => $suggestion->id,
                'knowledge_id' => $suggestion->target_knowledge_id,
            ]);

            return [
                'success' => true,
                'message' => 'Knowledge improvement undone. Entry has been disabled.',
            ];
        }

        return [
            'success' => false,
            'message' => 'No target knowledge to undo.',
        ];
    }

    /**
     * Undo a prompt improvement.
     *
     * @return array{success: bool, message: string}
     */
    protected function undoPromptImprovement(AIImprovementSuggestion $suggestion): array
    {
        // Disable the override instead of deleting. Saved PER MODEL (not a
        // query-builder bulk update) so model events fire: the critical-
        // knowledge cache clears AND AIPromptOverrideObserver mirrors the
        // disable to phasea (Phase 34 Half B — bulk updates bypass observers).
        $overrides = AIPromptOverride::query()
            ->where('suggestion_id', $suggestion->id)
            ->get();

        foreach ($overrides as $override) {
            $override->update(['enabled' => false]);
        }

        $updated = $overrides->count();

        if ($updated > 0) {
            $suggestion->update([
                'status' => AIImprovementStatus::Approved,
                'applied_at' => null,
            ]);

            Log::info('Undid prompt improvement', [
                'suggestion_id' => $suggestion->id,
            ]);

            return [
                'success' => true,
                'message' => 'Prompt improvement undone. Override has been disabled.',
            ];
        }

        return [
            'success' => false,
            'message' => 'No prompt override found to undo.',
        ];
    }
}
