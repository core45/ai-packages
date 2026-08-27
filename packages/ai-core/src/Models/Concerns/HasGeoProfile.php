<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

use Core45\AiCore\Models\GeoProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Trait for models that support GEO (Generative Engine Optimization) scoring.
 *
 * Models using this trait must implement geoProfileData() to provide
 * the content fields used for scoring.
 *
 * NOTE: the scoring service itself (a host-namespaced GEO scorer service in
 * the reference implementation) is not part of this host application yet,
 * so no `scoreGeo()` write helper is provided here. Only read access to
 * profiles already stored via GeoProfile is exposed.
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
    public function getGeoGrade(?string $locale = null): ?string
    {
        return $this->geoProfile($locale)?->grade;
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
