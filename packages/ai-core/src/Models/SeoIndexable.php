<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Contracts\AIQueryable;
use Core45\AiCore\Database\Factories\SeoIndexableFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $indexable_type
 * @property int $indexable_id
 * @property string $locale
 * @property string|null $title
 * @property string|null $description
 * @property string|null $canonical
 * @property string|null $robots
 * @property bool $is_noindex
 * @property array<string, mixed>|null $og_data
 * @property array<string, mixed>|null $twitter_data
 * @property array<string, mixed>|null $schema_json
 * @property string|null $og_title
 * @property string|null $og_description
 * @property string|null $og_image
 * @property string|null $twitter_title
 * @property string|null $twitter_description
 * @property string|null $twitter_image
 * @property int|null $seo_score
 * @property int|null $readability_score
 * @property string|null $overall_status
 * @property array<int, array<string, mixed>>|null $analysis_issues
 * @property array{primary?: string, secondary?: array<int, string>}|null $keyphrases
 * @property int $internal_links_inbound
 * @property int $internal_links_outbound
 * @property Carbon|null $links_counted_at
 * @property bool $is_cornerstone
 * @property Carbon|null $last_modified
 * @property Carbon|null $analyzed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $primary_keyphrase
 * @property-read array<int, string> $secondary_keyphrases
 * @property-read Model $indexable
 *
 * @method static Builder<static> forLocale(string $locale)
 * @method static Builder<static> indexable()
 * @method static Builder<static> cornerstone()
 * @method static Builder<static> withStatus(string $status)
 */
class SeoIndexable extends Model implements AIQueryable
{
    /** @use HasFactory<SeoIndexableFactory> */
    use HasFactory;

    /**
     * Package-local factory resolution: Laravel's default convention looks in
     * the consumer app's `Database\Factories` namespace first; point it back
     * at the package factory explicitly.
     */
    protected static function newFactory(): SeoIndexableFactory
    {
        return SeoIndexableFactory::new();
    }

    protected $table = 'seo_indexables';

    /** @var list<string> */
    protected $fillable = [
        'indexable_type',
        'indexable_id',
        'locale',
        'title',
        'description',
        'canonical',
        'robots',
        'is_noindex',
        'og_data',
        'twitter_data',
        'schema_json',
        'og_title',
        'og_description',
        'og_image',
        'twitter_title',
        'twitter_description',
        'twitter_image',
        'seo_score',
        'readability_score',
        'overall_status',
        'analysis_issues',
        'keyphrases',
        'internal_links_inbound',
        'internal_links_outbound',
        'links_counted_at',
        'is_cornerstone',
        'last_modified',
        'analyzed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'og_data' => 'array',
            'twitter_data' => 'array',
            'schema_json' => 'array',
            'analysis_issues' => 'array',
            'keyphrases' => 'array',
            'is_noindex' => 'boolean',
            'is_cornerstone' => 'boolean',
            'seo_score' => 'integer',
            'readability_score' => 'integer',
            'internal_links_inbound' => 'integer',
            'internal_links_outbound' => 'integer',
            'last_modified' => 'datetime',
            'analyzed_at' => 'datetime',
            'links_counted_at' => 'datetime',
        ];
    }

    /**
     * Get the parent indexable model (Post, etc.).
     *
     * @return MorphTo<Model, $this>
     */
    public function indexable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeIndexable(Builder $query): Builder
    {
        return $query->where('is_noindex', false);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCornerstone(Builder $query): Builder
    {
        return $query->where('is_cornerstone', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithStatus(Builder $query, string $status): Builder
    {
        return $query->where('overall_status', $status);
    }

    public function getPrimaryKeyphraseAttribute(): ?string
    {
        return $this->keyphrases['primary'] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function getSecondaryKeyphrasesAttribute(): array
    {
        return $this->keyphrases['secondary'] ?? [];
    }

    /**
     * Get effective OpenGraph data with user overrides taking priority.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveOgData(): array
    {
        $ogData = $this->og_data ?? [];

        if ($this->og_title !== null) {
            $ogData['og:title'] = $this->og_title;
        }

        if ($this->og_description !== null) {
            $ogData['og:description'] = $this->og_description;
        }

        if ($this->og_image !== null) {
            $ogData['og:image'] = $this->og_image;
        }

        return $ogData;
    }

    /**
     * Get effective Twitter Card data with user overrides taking priority.
     *
     * @return array<string, mixed>
     */
    public function getEffectiveTwitterData(): array
    {
        $twitterData = $this->twitter_data ?? [];

        if ($this->twitter_title !== null) {
            $twitterData['twitter:title'] = $this->twitter_title;
        }

        if ($this->twitter_description !== null) {
            $twitterData['twitter:description'] = $this->twitter_description;
        }

        if ($this->twitter_image !== null) {
            $twitterData['twitter:image'] = $this->twitter_image;
        }

        return $twitterData;
    }

    public function needsImprovement(): bool
    {
        return $this->overall_status === 'needs_improvement';
    }

    public function isGood(): bool
    {
        return $this->overall_status === 'good';
    }

    // ==================== AIQueryable Implementation ====================

    /**
     * @return list<string>
     */
    public function getAIQueryableFields(): array
    {
        return [
            'id', 'indexable_type', 'indexable_id', 'locale',
            'title', 'description', 'canonical', 'robots',
            'is_noindex', 'is_cornerstone',
            'seo_score', 'readability_score', 'overall_status',
            'keyphrases', 'analysis_issues',
            'internal_links_inbound', 'internal_links_outbound',
            'analyzed_at', 'created_at', 'updated_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAIFilterScopes(): array
    {
        return [
            'forLocale' => 'Filter by locale (e.g., en, de, pl, es, fr, it)',
            'indexable' => 'Only indexable pages (not noindex)',
            'cornerstone' => 'Only cornerstone content',
            'withStatus' => 'Filter by status: good, needs_improvement, or poor',
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function getAIActions(): array
    {
        return [];
    }

    public function getFilamentResource(): ?string
    {
        return null;
    }

    public static function getAIModelLabel(): string
    {
        return 'SEO Indexable';
    }

    public static function getAIModelPluralLabel(): string
    {
        return 'SEO Indexables';
    }
}
