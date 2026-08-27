<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Concerns;

use Core45\AiCore\Contracts\AIContentable;
use Core45\AiCore\Models\TranslationLock;
use Core45\AiPosts\Jobs\TranslatePostToLocale;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Detects missing translations on save and provides a header action
 * to dispatch AI translation jobs for all missing locales.
 */
trait HandlesTranslationOnSave
{
    protected function checkTranslationsAfterSave(): void
    {
        $this->notifyIfTranslationsMissing();
    }

    protected function translateMissingAction(): Action
    {
        return Action::make('translateMissing')
            ->label(__('Translate missing languages'))
            ->icon(Heroicon::OutlinedLanguage)
            ->color('info')
            ->visible(fn (): bool => self::isTranslationAiEnabled())
            ->requiresConfirmation()
            ->modalHeading(__('Translate missing languages'))
            ->modalDescription(__('This will use AI to translate content into all languages that currently have no content. The source language will be automatically detected.'))
            ->modalSubmitActionLabel(__('Start translations'))
            ->action(function (): void {
                /** @var AIContentable&Model $post */
                $post = $this->getRecord();
                $missingLocales = self::getMissingLocales($post);

                if ($missingLocales === []) {
                    Notification::make()
                        ->title(__('All languages translated'))
                        ->body(__('This post already has content in all configured languages.'))
                        ->info()
                        ->send();

                    return;
                }

                $sourceLocale = self::detectSourceLocale($post);
                if ($sourceLocale === null) {
                    Notification::make()
                        ->title(__('No source content'))
                        ->body(__('No language has content that can be used as a translation source.'))
                        ->warning()
                        ->send();

                    return;
                }

                /** @var int|null $userId */
                $userId = Auth::id();

                foreach ($missingLocales as $targetLocale) {
                    $lock = TranslationLock::acquire(
                        $post,
                        $targetLocale,
                        $post->takeTranslationSnapshot($targetLocale),
                        5,
                    );
                    TranslatePostToLocale::dispatch($post, $sourceLocale, $targetLocale, $userId, $lock->lock_token);
                }

                Notification::make()
                    ->title(__('Translations queued'))
                    ->body(__(':count translation job(s) have been queued from :source.', [
                        'count' => count($missingLocales),
                        'source' => self::getLocaleName($sourceLocale),
                    ]))
                    ->success()
                    ->send();
            });
    }

    private function notifyIfTranslationsMissing(): void
    {
        /** @var AIContentable&Model $post */
        $post = $this->getRecord();
        $missingLocales = self::getMissingLocales($post);

        if ($missingLocales !== []) {
            $names = array_map(
                fn (string $locale): string => self::getLocaleName($locale),
                $missingLocales,
            );

            Notification::make()
                ->title(__('Missing translations'))
                ->body(__('Content is missing for: :languages. Use the "Translate missing languages" button to auto-translate.', [
                    'languages' => implode(', ', $names),
                ]))
                ->warning()
                ->persistent()
                ->send();
        }
    }

    /**
     * @return list<string>
     */
    private static function getMissingLocales(AIContentable&Model $post): array
    {
        /** @var array<string, mixed> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);
        $missing = [];

        foreach (array_keys($supportedLocales) as $locale) {
            $translation = $post->translate($locale);
            if ($translation === null || empty($translation->title)) {
                $missing[] = $locale;
            }
        }

        return $missing;
    }

    private static function detectSourceLocale(AIContentable&Model $post): ?string
    {
        /** @var array<string, mixed> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        foreach (array_keys($supportedLocales) as $locale) {
            $translation = $post->translate($locale);
            if ($translation !== null && !empty($translation->title) && !empty($translation->main_text)) {
                return $locale;
            }
        }

        return null;
    }

    private static function isTranslationAiEnabled(): bool
    {
        return (bool) config('ai.assistant.enabled', false);
    }

    private static function getLocaleName(string $locale): string
    {
        /** @var array<string, array{name: string}> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        return $supportedLocales[$locale]['name'] ?? $locale;
    }
}
