<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Records a similarity check result between content items.
 *
 * Tracks the similarity score between a model's content field and another
 * piece of content (internal or external).
 *
 * @property int $id
 * @property string $checkable_type
 * @property int $checkable_id
 * @property string $field
 * @property string $locale
 * @property string $compared_with_type
 * @property string $compared_with_reference
 * @property float $similarity_score
 * @property string $status
 * @property array<string, mixed>|null $details
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Model $checkable
 */
class ContentSimilarityCheck extends Model
{
    public const STATUS_PASS = 'pass';

    public const STATUS_WARNING = 'warning';

    public const STATUS_FAIL = 'fail';

    public const TYPE_INTERNAL = 'internal';

    public const TYPE_EXTERNAL_URL = 'external_url';

    /** @var list<string> */
    protected $fillable = [
        'checkable_type',
        'checkable_id',
        'field',
        'locale',
        'compared_with_type',
        'compared_with_reference',
        'similarity_score',
        'status',
        'details',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'similarity_score' => 'float',
            'details' => 'array',
        ];
    }

    /**
     * Get the checkable model (Product, Post, etc.).
     *
     * @return MorphTo<Model, $this>
     */
    public function checkable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to filter by status.
     *
     * @param  Builder<ContentSimilarityCheck>  $query
     * @return Builder<ContentSimilarityCheck>
     */
    public function scopeByStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get failed checks.
     *
     * @param  Builder<ContentSimilarityCheck>  $query
     * @return Builder<ContentSimilarityCheck>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAIL);
    }

    /**
     * Scope to get warnings.
     *
     * @param  Builder<ContentSimilarityCheck>  $query
     * @return Builder<ContentSimilarityCheck>
     */
    public function scopeWarnings(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WARNING);
    }

    /**
     * Scope to filter by model type.
     *
     * @param  Builder<ContentSimilarityCheck>  $query
     * @return Builder<ContentSimilarityCheck>
     */
    public function scopeForModelType(Builder $query, string $modelClass): Builder
    {
        return $query->where('checkable_type', $modelClass);
    }

    /**
     * Scope to get checks with high similarity.
     *
     * @param  Builder<ContentSimilarityCheck>  $query
     * @return Builder<ContentSimilarityCheck>
     */
    public function scopeHighSimilarity(Builder $query, float $threshold = 0.6): Builder
    {
        return $query->where('similarity_score', '>=', $threshold);
    }

    /**
     * Check if this result indicates a failure (too similar).
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAIL;
    }

    /**
     * Check if this result indicates a warning.
     */
    public function isWarning(): bool
    {
        return $this->status === self::STATUS_WARNING;
    }

    /**
     * Check if this result passed (acceptable similarity).
     */
    public function isPassed(): bool
    {
        return $this->status === self::STATUS_PASS;
    }

    /**
     * Get a human-readable description of what was compared.
     */
    public function getComparedWithDescription(): string
    {
        if ($this->compared_with_type === self::TYPE_INTERNAL) {
            return "Internal: {$this->compared_with_reference}";
        }

        return "External URL: {$this->compared_with_reference}";
    }

    /**
     * Get the similarity score as a percentage.
     */
    public function getSimilarityPercentage(): float
    {
        return round($this->similarity_score * 100, 1);
    }
}
