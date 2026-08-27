<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

use Core45\AiCore\Models\AiDetection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Trait for models that can be checked for AI-generated content.
 *
 * Implement getAiDetectableFields() to define which translatable fields
 * should be concatenated and sent for detection.
 */
trait HasAiDetection
{
    /**
     * @return MorphMany<AiDetection, $this>
     */
    public function aiDetections(): MorphMany
    {
        return $this->morphMany(AiDetection::class, 'detectable');
    }

    /**
     * Get the latest completed detection for a specific locale.
     * Uses eager-loaded relation when available to avoid N+1.
     */
    public function latestAiDetection(?string $locale = null): ?AiDetection
    {
        $locale = $locale ?? app()->getLocale();

        if ($this->relationLoaded('aiDetections')) {
            return $this->aiDetections
                ->where('locale', $locale)
                ->where('status', 'done')
                ->sortByDesc('created_at')
                ->first();
        }

        return $this->aiDetections()
            ->where('locale', $locale)
            ->where('status', 'done')
            ->latest()
            ->first();
    }

    /**
     * Get the latest detection score for a locale.
     */
    public function getAiDetectionScore(?string $locale = null): ?float
    {
        return $this->latestAiDetection($locale)?->score;
    }

    /**
     * Get the highest AI detection score across all locales,
     * considering only the LATEST detection per locale.
     * Uses eager-loaded relation when available to avoid N+1.
     */
    public function getHighestAiDetectionScore(): ?float
    {
        if ($this->relationLoaded('aiDetections')) {
            $latestPerLocale = $this->aiDetections
                ->where('status', 'done')
                ->whereNotNull('score')
                ->groupBy('locale')
                ->map(fn (Collection $group) => $group->sortByDesc('created_at')->first());

            if ($latestPerLocale->isEmpty()) {
                return null;
            }

            return (float) $latestPerLocale->max('score');
        }

        /** @var array<string, array<string, mixed>> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);
        $locales = array_keys($supportedLocales);

        $maxScore = null;
        foreach ($locales as $locale) {
            $score = $this->getAiDetectionScore($locale);
            if ($score !== null && ($maxScore === null || $score > $maxScore)) {
                $maxScore = $score;
            }
        }

        return $maxScore;
    }

    /**
     * Check if there is a pending detection job for this record.
     */
    public function hasAiDetectionPending(): bool
    {
        if ($this->relationLoaded('aiDetections')) {
            return $this->aiDetections
                ->where('status', 'pending')
                ->isNotEmpty();
        }

        return $this->aiDetections()
            ->where('status', 'pending')
            ->exists();
    }

    /**
     * Get the text content to analyze for a given locale.
     * Strips HTML and concatenates configured fields.
     */
    public function getAiDetectableText(string $locale): string
    {
        $fields = $this->getAiDetectableFields();
        $translation = $this->translate($locale);

        if ($translation === null) {
            return '';
        }

        $parts = [];
        foreach ($fields as $field) {
            $value = $translation->{$field} ?? '';
            $parts[] = strip_tags((string) $value);
        }

        return implode("\n\n", array_filter($parts));
    }

    /**
     * Count words in text using Unicode-safe regex.
     * Works correctly for accented characters, German umlauts, Polish diacritics, etc.
     *
     * This regex must remain byte-identical to phasea's App\AI\Support\WordCounter
     * so the local minimum-word pre-check and remote ingress gate agree (D-27-3).
     */
    public static function countWords(string $text): int
    {
        return (int) preg_match_all('/\pL+/u', $text);
    }

    /**
     * Check if any of the detectable content fields changed on the latest translation save.
     * Used by the observer to avoid dispatching detection on unrelated saves.
     */
    public function hasDetectableContentChanged(): bool
    {
        $fields = $this->getAiDetectableFields();

        /** @var array<string, array<string, mixed>> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        foreach (array_keys($supportedLocales) as $locale) {
            $translation = $this->translate($locale);
            if ($translation === null) {
                continue;
            }

            if ($translation->wasRecentlyCreated) {
                return true;
            }

            foreach ($fields as $field) {
                if ($translation->wasChanged($field)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Define which translatable fields to concatenate for AI detection.
     *
     * @return list<string>
     */
    abstract public function getAiDetectableFields(): array;
}
