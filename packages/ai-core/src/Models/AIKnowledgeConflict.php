<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Database\Factories\AIKnowledgeConflictFactory;
use Core45\AiCore\Enums\AIConflictStatus;
use Core45\AiCore\Enums\AIConflictType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;

/**
 * AI Knowledge Conflict - tracks contradictory knowledge entries.
 *
 * @property int $id
 * @property int $knowledge_a_id
 * @property int $knowledge_b_id
 * @property AIConflictType $conflict_type
 * @property array<int, string> $overlap_keywords
 * @property float $overlap_score
 * @property AIConflictStatus $status
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read AILearnedKnowledge $knowledgeA
 * @property-read AILearnedKnowledge $knowledgeB
 * @property-read User|null $resolver
 */
class AIKnowledgeConflict extends Model
{
    /** @use HasFactory<AIKnowledgeConflictFactory> */
    use HasFactory;

    /** Package-local factory resolution (consumer apps have no factory for this model). */
    protected static function newFactory(): AIKnowledgeConflictFactory
    {
        return AIKnowledgeConflictFactory::new();
    }

    protected $table = 'ai_knowledge_conflicts';

    protected $fillable = [
        'knowledge_a_id',
        'knowledge_b_id',
        'conflict_type',
        'overlap_keywords',
        'overlap_score',
        'status',
        'resolved_by',
        'resolved_at',
        'resolution_notes',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'conflict_type' => AIConflictType::class,
            'overlap_keywords' => 'array',
            'overlap_score' => 'decimal:2',
            'status' => AIConflictStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Relationships
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return BelongsTo<AILearnedKnowledge, $this>
     */
    public function knowledgeA(): BelongsTo
    {
        return $this->belongsTo(AILearnedKnowledge::class, 'knowledge_a_id');
    }

    /**
     * @return BelongsTo<AILearnedKnowledge, $this>
     */
    public function knowledgeB(): BelongsTo
    {
        return $this->belongsTo(AILearnedKnowledge::class, 'knowledge_b_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Scopes
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AIConflictStatus::Detected->value,
            AIConflictStatus::Reviewed->value,
        ]);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfStatus(Builder $query, AIConflictStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the conflict with notes.
     */
    public function resolve(User $resolver, string $notes = ''): void
    {
        $this->update([
            'status' => AIConflictStatus::Resolved,
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Dismiss the conflict (not a real issue).
     */
    public function dismiss(User $resolver, string $notes = ''): void
    {
        $this->update([
            'status' => AIConflictStatus::Dismissed,
            'resolved_by' => $resolver->id,
            'resolved_at' => now(),
            'resolution_notes' => $notes,
        ]);
    }

    /**
     * Check if this conflict is still unresolved.
     */
    public function isUnresolved(): bool
    {
        return in_array($this->status, [
            AIConflictStatus::Detected,
            AIConflictStatus::Reviewed,
        ]);
    }
}
