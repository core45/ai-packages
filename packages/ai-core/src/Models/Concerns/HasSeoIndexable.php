<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

use Core45\AiCore\Models\SeoIndexable;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Trait for models that can have SEO indexable metadata (Posts, Products, etc.).
 */
trait HasSeoIndexable
{
    /**
     * Get all SEO indexables for this model (all locales).
     *
     * @return MorphMany<SeoIndexable, $this>
     */
    public function seoIndexables(): MorphMany
    {
        return $this->morphMany(SeoIndexable::class, 'indexable');
    }

    /**
     * Get SEO indexable for a specific locale.
     */
    public function seoIndexable(?string $locale = null): ?SeoIndexable
    {
        $locale = $locale ?? app()->getLocale();

        return $this->seoIndexables()
            ->where('locale', $locale)
            ->first();
    }

    /**
     * Get or create SEO indexable for a locale.
     */
    public function getOrCreateSeoIndexable(?string $locale = null): SeoIndexable
    {
        $locale = $locale ?? app()->getLocale();

        return $this->seoIndexables()->firstOrCreate(
            ['locale' => $locale],
            [
                'title' => $this->seo_title ?? $this->title ?? $this->name ?? null,
                'description' => $this->seo_description ?? $this->excerpt ?? $this->summary ?? null,
                'canonical' => $this->url ?? null,
            ]
        );
    }

    /**
     * Check if this content is marked as cornerstone.
     */
    public function isCornerstone(?string $locale = null): bool
    {
        return $this->seoIndexable($locale)?->is_cornerstone ?? false;
    }

    /**
     * Get SEO score for this content.
     */
    public function getSeoScore(?string $locale = null): ?int
    {
        return $this->seoIndexable($locale)?->seo_score;
    }

    /**
     * Get readability score for this content.
     */
    public function getReadabilityScore(?string $locale = null): ?int
    {
        return $this->seoIndexable($locale)?->readability_score;
    }

    /**
     * Check if content needs SEO improvement.
     */
    public function needsSeoImprovement(?string $locale = null): bool
    {
        return $this->seoIndexable($locale)?->needsImprovement() ?? true;
    }

    /**
     * Mark this content as cornerstone for a specific locale.
     */
    public function markAsCornerstone(?string $locale = null): void
    {
        $indexable = $this->getOrCreateSeoIndexable($locale);
        $indexable->update(['is_cornerstone' => true]);
    }

    /**
     * Unmark this content as cornerstone for a specific locale.
     */
    public function unmarkAsCornerstone(?string $locale = null): void
    {
        $indexable = $this->seoIndexable($locale);
        $indexable?->update(['is_cornerstone' => false]);
    }

    /**
     * Set the cornerstone status for all locales.
     */
    public function setCornerstoneForAllLocales(bool $isCornerstone): void
    {
        $this->seoIndexables()->update(['is_cornerstone' => $isCornerstone]);
    }
}
