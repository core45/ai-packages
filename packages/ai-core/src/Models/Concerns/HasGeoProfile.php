<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

use Core45\AiCore\Enums\GeoGrade;
use Core45\AiCore\Models\GeoProfile;
use Core45\AiCore\Services\Geo\GeoScorerService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Trait for models that support GEO (Generative Engine Optimization) scoring.
 *
 * Models using this trait must implement geoProfileData() to provide
 * the content fields used for scoring.
 *
 * D7: both halves live here. `scoreGeo()` is the WRITE side and delegates to
 * {@see GeoScorerService}, which is now part of this package; the rest is read
 * access to profiles already stored. An earlier revision of this docblock said
 * no write helper was provided because the scorer was still host-owned — that
 * is no longer true, and leaving it would have described the opposite of the
 * code below.
 */
trait HasGeoProfile
{
    /**
     * @return MorphMany<GeoProfile, $this>
     */
    public function geoProfiles(): MorphMany
    {
        return $this->morphMany(GeoProfile::class, 'model');
    }

    /**
     * Get the GEO profile for a specific locale.
     */
    public function geoProfile(?string $locale = null): ?GeoProfile
    {
        $locale = $locale ?? app()->getLocale();

        if ($this->relationLoaded('geoProfiles')) {
            return $this->geoProfiles
                ->where('locale', $locale)
                ->first();
        }

        return $this->geoProfiles()
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Get the GEO score for a specific locale.
     */
    public function getGeoScore(?string $locale = null): ?int
    {
        return $this->geoProfile($locale)?->score;
    }

    /**
     * Get the GEO grade for a specific locale.
     */
    public function getGeoGrade(?string $locale = null): ?GeoGrade
    {
        return $this->geoProfile($locale)?->grade;
    }

    /**
     * Re-score GEO for this model and upsert the profile.
     *
     * Resolved from the container rather than instantiated, so a consuming app
     * can bind its own scorer without touching every model that uses the trait.
     */
    public function scoreGeo(?string $locale = null): GeoProfile
    {
        $locale = $locale ?? app()->getLocale();

        return app(GeoScorerService::class)->score($this, $locale);
    }

    /**
     * Scope to order by GEO score via subquery.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByGeoScore(Builder $query, string $direction = 'desc', ?string $locale = null): Builder
    {
        $locale = $locale ?? app()->getLocale();

        return $query->orderBy(
            GeoProfile::query()
                ->select('score')
                ->whereColumn('geo_profiles.model_id', $this->getTable().'.id')
                ->where('geo_profiles.model_type', static::class)
                ->where('geo_profiles.locale', $locale)
                ->limit(1),
            $direction,
        );
    }

    /**
     * Provide the content data used for GEO scoring.
     *
     * @return array<string, mixed>
     */
    abstract public function geoProfileData(string $locale): array;
}
