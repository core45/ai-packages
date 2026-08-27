<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Database\Factories\AIKnowledgeGapFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;

/**
 * AI Knowledge Gap - tracks questions the AI couldn't answer confidently.
 *
 * @property int $id
 * @property string $question
 * @property string|null $ai_response
 * @property string $confidence_score
 * @property string $audience
 * @property int|null $conversation_id
 * @property int $occurrence_count
 * @property Carbon|null $last_occurred_at
 * @property array<int, string>|null $similar_questions
 * @property string $status
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property int|null $resolution_knowledge_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AIConversation|null $conversation
 * @property-read User|null $resolver
 * @property-read AILearnedKnowledge|null $resolutionKnowledge
 */
class AIKnowledgeGap extends Model
{
    /** @use HasFactory<AIKnowledgeGapFactory> */
    use HasFactory;

    /** Package-local factory resolution (consumer apps have no factory for this model). */
    protected static function newFactory(): AIKnowledgeGapFactory
    {
        return AIKnowledgeGapFactory::new();
    }

    protected $table = 'ai_knowledge_gaps';

    protected $fillable = [
        'question',
        'ai_response',
        'confidence_score',
        'audience',
        'conversation_id',
        'occurrence_count',
        'last_occurred_at',
        'similar_questions',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_knowledge_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confidence_score' => 'decimal:2',
            'occurrence_count' => 'integer',
            'last_occurred_at' => 'datetime',
            'similar_questions' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<AIConversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AIConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * @return BelongsTo<AILearnedKnowledge, $this>
     */
    public function resolutionKnowledge(): BelongsTo
    {
        return $this->belongsTo(AILearnedKnowledge::class, 'resolution_knowledge_id');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->where('status', 'resolved');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeIgnored(Builder $query): Builder
    {
        return $query->where('status', 'ignored');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->where('audience', $audience);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFrequent(Builder $query, int $minCount = 3): Builder
    {
        return $query->where('occurrence_count', '>=', $minCount);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeLowConfidence(Builder $query, float $threshold = 0.4): Builder
    {
        return $query->where('confidence_score', '<', $threshold);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopePrioritized(Builder $query): Builder
    {
        return $query->orderByDesc('occurrence_count')
            ->orderBy('confidence_score');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Increment occurrence count and optionally add a similar question.
     */
    public function incrementOccurrence(?string $similarQuestion = null): void
    {
        $this->increment('occurrence_count');
        $this->update(['last_occurred_at' => now()]);

        if ($similarQuestion && $similarQuestion !== $this->question) {
            $similar = $this->similar_questions ?? [];
            if (!in_array($similarQuestion, $similar)) {
                $similar[] = $similarQuestion;
                // Keep last 10 similar questions
                $this->update(['similar_questions' => array_slice($similar, -10)]);
            }
        }
    }

    /**
     * Resolve the gap by linking it to a knowledge entry.
     */
    public function resolve(User $resolver, AILearnedKnowledge $knowledge): void
    {
        $this->update([
            'status' => 'resolved',
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
            'resolution_knowledge_id' => $knowledge->id,
        ]);
    }

    /**
     * Mark the gap as ignored (not worth addressing).
     */
    public function ignore(User $resolver): void
    {
        $this->update([
            'status' => 'ignored',
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Check if this gap is open.
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Check if this gap is resolved.
     */
    public function isResolved(): bool
    {
        return $this->status === 'resolved';
    }

    /**
     * Check if this is a frequent gap (asked multiple times).
     */
    public function isFrequent(int $threshold = 3): bool
    {
        return $this->occurrence_count >= $threshold;
    }

    /**
     * Get status options for forms.
     *
     * @return array<string, string>
     */
    public static function getStatusOptions(): array
    {
        return [
            'open' => __('ai_knowledge.gap_status.open'),
            'resolved' => __('ai_knowledge.gap_status.resolved'),
            'ignored' => __('ai_knowledge.gap_status.ignored'),
        ];
    }

    /**
     * Get audience options for forms.
     *
     * @return array<string, string>
     */
    public static function getAudienceOptions(): array
    {
        return AILearnedKnowledge::getAudienceOptions();
    }
}
