<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Database\Factories\AILearnedKnowledgeFactory;
use Core45\AiCore\Enums\AIKnowledgeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;

/**
 * AI Knowledge Base entry.
 *
 * @property int $id
 * @property AIKnowledgeType $type
 * @property string $tier
 * @property string $audience
 * @property string $title
 * @property string $content
 * @property array<int, string>|null $keywords
 * @property string $source_type
 * @property bool $enabled
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property int $use_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $creator
 */
class AILearnedKnowledge extends Model
{
    /** @use HasFactory<AILearnedKnowledgeFactory> */
    use HasFactory;

    /** Package-local factory resolution (consumer apps have no factory for this model). */
    protected static function newFactory(): AILearnedKnowledgeFactory
    {
        return AILearnedKnowledgeFactory::new();
    }

    use Searchable;

    protected $table = 'ai_learned_knowledge';

    protected $fillable = [
        'type',
        'tier',
        'audience',
        'title',
        'content',
        'keywords',
        'source_type',
        'enabled',
        'created_by',
        'last_used_at',
        'use_count',
    ];

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => AIKnowledgeType::class,
            'keywords' => 'array',
            'enabled' => 'boolean',
            'last_used_at' => 'datetime',
            'use_count' => 'integer',
        ];
    }

    // Relationships

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scout Configuration

    public function searchableAs(): string
    {
        return config('scout.prefix').'ai_knowledge';
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $array = [
            'id' => $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'keywords' => $this->keywords ?? [],
            'type' => $this->type->value,
            'tier' => $this->tier,
            'audience' => $this->audience,
            'enabled' => $this->enabled,
        ];

        return $array;
    }

    public function shouldBeSearchable(): bool
    {
        return $this->enabled;
    }

    // Scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeCritical(Builder $query): Builder
    {
        return $query->where('tier', 'critical');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeContextual(Builder $query): Builder
    {
        return $query->where('tier', 'contextual');
    }

    /**
     * Filter by audience visibility.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return match ($audience) {
            'admin' => $query, // Admins see everything
            'customer' => $query->whereIn('audience', ['public', 'customer']),
            default => $query->where('audience', 'public'), // Anonymous = public only
        };
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOfType(Builder $query, AIKnowledgeType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInstructions(Builder $query): Builder
    {
        return $query->where('type', AIKnowledgeType::Instruction);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFacts(Builder $query): Builder
    {
        return $query->where('type', AIKnowledgeType::Fact);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFaqs(Builder $query): Builder
    {
        return $query->where('type', AIKnowledgeType::Faq);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForbidden(Builder $query): Builder
    {
        return $query->where('type', AIKnowledgeType::Forbidden);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRequired(Builder $query): Builder
    {
        return $query->where('type', AIKnowledgeType::Required);
    }

    // Helper Methods

    /**
     * Record that this knowledge was used.
     */
    public function recordUsage(): void
    {
        $this->increment('use_count');
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Get type options for forms.
     *
     * @return array<string, string>
     */
    public static function getTypeOptions(): array
    {
        return AIKnowledgeType::options();
    }

    /**
     * Get tier options for forms.
     *
     * @return array<string, string>
     */
    public static function getTierOptions(): array
    {
        return [
            'critical' => __('ai_knowledge.tier.critical'),
            'contextual' => __('ai_knowledge.tier.contextual'),
        ];
    }

    /**
     * Get audience options for forms.
     *
     * @return array<string, string>
     */
    public static function getAudienceOptions(): array
    {
        return [
            'public' => __('ai_knowledge.audience.public'),
            'customer' => __('ai_knowledge.audience.customer'),
            'admin' => __('ai_knowledge.audience.admin'),
        ];
    }
}
