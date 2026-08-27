<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

use Core45\AiCore\Models\TranslationLock;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasTranslationLocks
{
    /**
     * @return MorphMany<TranslationLock, $this>
     */
    public function translationLocks(): MorphMany
    {
        return $this->morphMany(TranslationLock::class, 'lockable');
    }

    public function isLocaleTranslationLocked(string $locale): bool
    {
        return TranslationLock::isLocked($this, $locale);
    }

    /**
     * @return array<int, string>
     */
    public function getLockedLocales(): array
    {
        return $this->translationLocks()
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->pluck('locale')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function restoreTranslationSnapshot(string $locale, array $snapshot): void
    {
        foreach ($snapshot as $field => $value) {
            $this->translateOrNew($locale)->$field = $value;
        }
        $this->save();
    }

    /**
     * @return array<int, string>
     */
    abstract public function getTranslatableFieldsForSnapshot(): array;

    /**
     * @return array<string, mixed>
     */
    public function takeTranslationSnapshot(string $locale): array
    {
        $snapshot = [];
        $translation = $this->translate($locale);

        if (!$translation) {
            return $snapshot;
        }

        foreach ($this->getTranslatableFieldsForSnapshot() as $field) {
            $snapshot[$field] = $translation->$field;
        }

        return $snapshot;
    }
}
