<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * NOTE: `grade` is a plain string ('A'..'F') here, not a host-namespaced
 * backed enum, because the host's enum directory is out of scope for this
 * change and no package in this application currently depends on that enum
 * existing.
 *
 * @property int $id
 * @property string $model_type
 * @property int $model_id
 * @property string $locale
 * @property int $score
 * @property string $grade
 * @property array<string, mixed>|null $audit
 * @property Carbon|null $citations_enriched_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class GeoProfile extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'model_type',
        'model_id',
        'locale',
        'score',
        'grade',
        'audit',
        'citations_enriched_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'audit' => 'array',
            'citations_enriched_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function model(): MorphTo
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
    public function scopeForModel(Builder $query, string $modelType): Builder
    {
        return $query->where('model_type', $modelType);
    }
}
