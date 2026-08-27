<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Actions;

use Core45\AiCore\Contracts\AIContentable;
use Core45\AiPosts\Jobs\BlogTopics\EnrichPostLinksJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Queues `EnrichPostLinksJob` for every locale that has content.
 *
 * NAMING NOTE: the class name and the action's machine name
 * (`enrichInternalLinks`) are kept unchanged because
 * `EnrichPostLinksJob`'s dispatch contract and existing references to this
 * action assume them — renaming those would ripple further than the honesty
 * fix requires. The user-facing label and notification text, however, no
 * longer claim link enrichment: `EnrichPostLinksJob` in this host only
 * refreshes the locale's SEO keyphrases (see that job's docblock for why
 * every actual link-enrichment collaborator is absent). Restore the
 * "Enrich Internal Links" wording once the link-management cluster is
 * ported back.
 */
class EnrichInternalLinksAction
{
    /**
     * Get all configured locale codes from laravellocalization config.
     *
     * @return list<string>
     */
    private static function getConfiguredLocales(): array
    {
        /** @var array<string, mixed> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        return array_keys($supportedLocales);
    }

    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'enrichInternalLinks')
            ->label(__('Refresh SEO Keyphrases'))
            ->icon(Heroicon::OutlinedLink)
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('This will refresh each language\'s SEO keyphrases from the post\'s SEO keywords. Internal link validation and injection are not available in this application.'))
            ->action(function (Model $record): void {
                if (!$record instanceof AIContentable || !method_exists($record, 'translate')) {
                    Notification::make()
                        ->title(__('Error'))
                        ->body(__('This action is only available for posts.'))
                        ->danger()
                        ->send();

                    return;
                }

                /** @var int|null $userId */
                $userId = auth()->id();
                $localeCount = 0;

                foreach (self::getConfiguredLocales() as $locale) {
                    $translation = $record->translate($locale);

                    if ($translation === null || empty($translation->main_text)) {
                        continue;
                    }

                    EnrichPostLinksJob::dispatch($record, $locale, null, $userId);
                    $localeCount++;
                }

                if ($localeCount === 0) {
                    Notification::make()
                        ->title(__('No content'))
                        ->body(__('No translations with content found for this post.'))
                        ->warning()
                        ->send();

                    return;
                }

                // Honest about what actually happens: EnrichPostLinksJob no longer
                // enriches links in this host (see its docblock) — it only
                // refreshes SEO keyphrases. The queue dispatch itself is real, so
                // ->success() is kept, but the wording must not promise link work.
                Notification::make()
                    ->title(__('SEO keyphrase refresh queued'))
                    ->body(__('SEO keyphrase population has been queued for :count languages. Internal link enrichment is not available in this application.', ['count' => $localeCount]))
                    ->success()
                    ->send();
            });
    }
}
