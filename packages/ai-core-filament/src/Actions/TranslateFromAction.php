<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Actions;

use Core45\AiCore\Models\TranslationLock;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Model-agnostic "Translate from" action for locale tabs.
 *
 * Renders a modal with source locale selector and dispatches
 * the provided job class to translate content between locales.
 *
 * NOTE: The source application special-cased a translation job
 * to grant it a longer lock TTL. That special case no longer applies, so
 * every job class now uses the standard 5-minute lock TTL.
 */
class TranslateFromAction
{
    /**
     * Create a Filament Action that dispatches a translation job.
     *
     * @param  class-string  $jobClass  Job class to dispatch with (model, sourceLocale, targetLocale, userId)
     */
    public static function make(string $targetLocale, string $jobClass): Action
    {
        return Action::make("translateFrom_{$targetLocale}")
            ->label(__('Translate from…'))
            ->icon(Heroicon::OutlinedLanguage)
            ->color('info')
            ->size('sm')
            ->visible(fn (?Model $record): bool => $record !== null && self::isAiEnabled())
            ->schema([
                Select::make('source_locale')
                    ->label(__('Source language'))
                    ->options(fn (): array => self::getLocaleOptions($targetLocale))
                    ->required()
                    ->helperText(__('Select the language to translate from. Existing content in the target language will be overwritten.')),
            ])
            ->modalHeading(__('Translate content'))
            ->modalDescription(__('AI will translate all content fields from the selected language to this tab\'s language. This operation runs in the background.'))
            ->modalSubmitActionLabel(__('Start translation'))
            ->action(function (array $data, Model $record) use ($targetLocale, $jobClass): void {
                $sourceLocale = $data['source_locale'];

                /** @var int|null $userId */
                $userId = Auth::id();

                $lockToken = null;
                if (method_exists($record, 'takeTranslationSnapshot')) {
                    $lock = TranslationLock::acquire(
                        $record,
                        $targetLocale,
                        $record->takeTranslationSnapshot($targetLocale),
                        5,
                    );
                    $lockToken = $lock->lock_token;
                }

                $jobClass::dispatch($record, $sourceLocale, $targetLocale, $userId, $lockToken);

                Notification::make()
                    ->title(__('Translation queued'))
                    ->body(__('Translation from :source to :target has been queued and will be processed shortly.', [
                        'source' => self::getLocaleName($sourceLocale),
                        'target' => self::getLocaleName($targetLocale),
                    ]))
                    ->success()
                    ->send();
            });
    }

    /**
     * Create a cancel translation action for a locale.
     */
    public static function makeCancelAction(string $targetLocale): Action
    {
        return Action::make("cancelTranslation_{$targetLocale}")
            ->label(__('Cancel translation'))
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->size('sm')
            ->visible(fn (?Model $record): bool => $record !== null
                && method_exists($record, 'isLocaleTranslationLocked')
                && $record->isLocaleTranslationLocked($targetLocale))
            ->requiresConfirmation()
            ->action(function (Model $record) use ($targetLocale): void {
                TranslationLock::cancelAndRestore($record, $targetLocale);

                Notification::make()
                    ->title(__('Translation cancelled and original content restored.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Create a Section component wrapping the translate action as a header action.
     *
     * @param  class-string  $jobClass
     */
    public static function makeSection(string $targetLocale, string $jobClass): Section
    {
        return Section::make(__('Translate content'))
            ->headerActions([
                self::make($targetLocale, $jobClass),
                self::makeCancelAction($targetLocale),
            ])
            ->collapsed()
            ->schema([]);
    }

    private static function isAiEnabled(): bool
    {
        return (bool) config('ai.assistant.enabled', false);
    }

    /**
     * Get locale options excluding the target locale.
     *
     * @return array<string, string>
     */
    private static function getLocaleOptions(string $excludeLocale): array
    {
        /** @var array<string, array{name: string}> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        $options = [];
        foreach ($supportedLocales as $code => $config) {
            if ($code === $excludeLocale) {
                continue;
            }
            $options[$code] = $config['name'];
        }

        return $options;
    }

    private static function getLocaleName(string $locale): string
    {
        /** @var array<string, array{name: string}> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        return $supportedLocales[$locale]['name'] ?? $locale;
    }
}
