<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Enums\AIImprovementStatus;
use Core45\AiCore\Enums\AIImprovementType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * AI Improvement Suggestion.
 *
 * @property int $id
 * @property AIImprovementType $type
 * @property string|null $category
 * @property string $priority
 * @property AIImprovementStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $applied_at
 * @property string|null $rejection_reason
 * @property string $title
 * @property string $problem_summary
 * @property array<string, mixed> $suggested_changes
 * @property array<int, int> $evidence_message_ids
 * @property int $pattern_count
 * @property float|null $confidence_score
 * @property int|null $target_knowledge_id
 * @property string|null $target_prompt_key
 * @property string|null $target_tool_class
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $reviewer
 * @property-read AILearnedKnowledge|null $targetKnowledge
 */
class AIImprovementSuggestion extends Model
{
    use Searchable;

    protected $table = 'ai_improvement_suggestions';

    protected $fillable = [
        'type',
        'category',
        'priority',
        'status',
        'reviewed_by',
        'reviewed_at',
        'applied_at',
        'rejection_reason',
        'title',
        'problem_summary',
        'suggested_changes',
        'evidence_message_ids',
        'pattern_count',
        'confidence_score',
        'target_knowledge_id',
        'target_prompt_key',
        'target_tool_class',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AIImprovementType::class,
            'status' => AIImprovementStatus::class,
            'reviewed_at' => 'datetime',
            'applied_at' => 'datetime',
            'suggested_changes' => 'array',
            'evidence_message_ids' => 'array',
            'pattern_count' => 'integer',
            'confidence_score' => 'decimal:2',
        ];
    }

    // Relationships

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return BelongsTo<AILearnedKnowledge, $this>
     */
    public function targetKnowledge(): BelongsTo
    {
        return $this->belongsTo(AILearnedKnowledge::class, 'target_knowledge_id');
    }

    // Scopes

    /**
     * @param  Builder<AIImprovementSuggestion>  $query
     * @return Builder<AIImprovementSuggestion>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AIImprovementStatus::Pending);
    }

    /**
     * @param  Builder<AIImprovementSuggestion>  $query
     * @return Builder<AIImprovementSuggestion>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', AIImprovementStatus::Approved);
    }

    /**
     * @param  Builder<AIImprovementSuggestion>  $query
     * @return Builder<AIImprovementSuggestion>
     */
    public function scopeApplied(Builder $query): Builder
    {
        return $query->where('status', AIImprovementStatus::Applied);
    }

    /**
     * @param  Builder<AIImprovementSuggestion>  $query
     * @return Builder<AIImprovementSuggestion>
     */
    public function scopeOfType(Builder $query, AIImprovementType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<AIImprovementSuggestion>  $query
     * @return Builder<AIImprovementSuggestion>
     */
    public function scopeHighPriority(Builder $query): Builder
    {
        return $query->whereIn('priority', ['high', 'critical']);
    }

    /**
     * @param  Builder<AIImprovementSuggestion>  $query
     * @return Builder<AIImprovementSuggestion>
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    // Actions

    /**
     * Add a message ID to the evidence list and increment pattern count.
     */
    public function addEvidence(int $messageId): void
    {
        $evidence = $this->evidence_message_ids ?? [];

        if (!in_array($messageId, $evidence)) {
            $evidence[] = $messageId;
            $this->update([
                'evidence_message_ids' => $evidence,
                'pattern_count' => count($evidence),
            ]);
        }
    }

    /**
     * Check if pattern count has reached the notification threshold.
     */
    public function hasReachedThreshold(): bool
    {
        return $this->pattern_count >= $this->type->notificationThreshold();
    }

    /**
     * Approve the suggestion.
     */
    public function approve(User $user): void
    {
        $this->update([
            'status' => AIImprovementStatus::Approved,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Reject the suggestion.
     */
    public function reject(User $user, ?string $reason = null): void
    {
        $this->update([
            'status' => AIImprovementStatus::Rejected,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    /**
     * Mark as applied.
     */
    public function markAsApplied(): void
    {
        $this->update([
            'status' => AIImprovementStatus::Applied,
            'applied_at' => now(),
        ]);
    }

    /**
     * Mark as needing development (for tool suggestions).
     */
    public function markAsNeedsDevelopment(): void
    {
        $this->update([
            'status' => AIImprovementStatus::NeedsDevelopment,
        ]);
    }

    /**
     * Get the evidence messages.
     *
     * @return Collection<int, AIConversationMessage>
     */
    public function getEvidenceMessages(): Collection
    {
        return AIConversationMessage::query()
            ->whereIn('id', $this->evidence_message_ids ?? [])
            ->with('conversation')
            ->get();
    }

    /**
     * Check if this suggestion can be auto-applied (not tool type).
     */
    public function canBeAutoApplied(): bool
    {
        return $this->type !== AIImprovementType::Tool
            && $this->status === AIImprovementStatus::Approved;
    }

    // Scout Search Methods

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'problem_summary' => $this->problem_summary,
            'category' => $this->category,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'priority' => $this->priority,
            'pattern_count' => $this->pattern_count,
            'keywords' => $this->extractSearchKeywords(),
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return config('scout.prefix').'ai_improvement_suggestions';
    }

    /**
     * Determine if the model should be searchable.
     */
    public function shouldBeSearchable(): bool
    {
        // Only index pending and approved suggestions (not rejected/applied)
        return in_array($this->status, [
            AIImprovementStatus::Pending,
            AIImprovementStatus::Approved,
        ]);
    }

    /**
     * Extract keywords from the suggestion for search indexing.
     *
     * @return array<int, string>
     */
    protected function extractSearchKeywords(): array
    {
        $stopWords = [
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will',
            'would', 'could', 'should', 'may', 'might', 'must', 'shall',
            'can', 'need', 'to', 'of', 'in', 'for', 'on', 'with', 'at',
            'by', 'from', 'as', 'into', 'through', 'and', 'but', 'or',
            'so', 'yet', 'not', 'this', 'that', 'these', 'those', 'what',
            'which', 'who', 'whom', 'how', 'when', 'where', 'why', 'your',
            'my', 'our', 'their', 'its', 'i', 'you', 'we', 'they', 'it',
        ];

        $text = $this->title.' '.$this->problem_summary;
        $words = str_word_count(strtolower($text), 1);
        $words = array_diff($words, $stopWords);
        $words = array_filter($words, fn ($w) => strlen($w) >= 3);

        return array_values(array_unique($words));
    }
}
