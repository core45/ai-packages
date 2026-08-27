<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Enums\GeoGrade;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * D7: `grade` casts to {@see GeoGrade}, a package-owned backed enum.
 *
 * It was a plain string here only because the grade enum had not been ported
 * yet — the source application always stored a `GeoGrade`. `GeoScorerService`
 * writes the enum, so the cast is what makes that write round-trip rather than
 * fail; the column is still `string(2)` and the stored values ('A'..'F') are
 * byte-identical to what the un-cast version wrote.
 *
 * @property int $id
 * @property string $model_type
 * @property int $model_id
 * @property string $locale
 * @property int $score
 * @property GeoGrade $grade
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
            'grade' => GeoGrade::class,
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
