<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Concerns;

use Core45\AiCore\Models\SeoIndexable;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Handles per-locale AI-powered SEO improvement triggered from the SeoAnalysisPanel blade view.
 *
 * NOTE: the source implementation dispatched its own SEO-improvement job
 * after checking a host-owned SEO improvement service's fixable-issues method.
 * Neither of those classes exists in this application, nor are they part of
 * the `core45/ai-core` or `core45/ai-posts` packages, so the actual
 * improvement dispatch was stripped. `improveSeoForLocale()` is kept as the
 * `wire:click` entry point expected by the SeoAnalysisPanel view, but it now
 * only validates the locale/record and informs the user the feature is
 * unavailable — see the task report for details.
 */
trait HandlesAiSeoImprovement
{
    /**
     * Improve SEO for a single locale via AI.
     *
     * Called from the SeoAnalysisPanel blade view via wire:click.
     */
    public function improveSeoForLocale(string $locale): void
    {
        /** @var array<string, mixed> $supportedLocales */
        $supportedLocales = config('laravellocalization.supportedLocales', []);

        if (!array_key_exists($locale, $supportedLocales)) {
            return;
        }

        if (!(bool) config('ai.assistant.enabled', false)) {
            Notification::make()
                ->title(__('AI Assistant is disabled'))
                ->body(__('Enable the AI assistant in settings to use this feature.'))
                ->warning()
                ->send();

            return;
        }

        try {
            /** @var Model $record */
            $record = $this->getRecord();

            if (!method_exists($record, 'seoIndexable')) {
                Notification::make()
                    ->title(__('Error'))
                    ->body(__('This model does not support SEO analysis.'))
                    ->danger()
                    ->send();

                return;
            }

            /** @var SeoIndexable|null $indexable */
            $indexable = $record->seoIndexable($locale);

            if ($indexable === null) {
                Notification::make()
                    ->title(__('No Issues Found'))
                    ->body(__('No SEO data found for this language.'))
                    ->info()
                    ->send();

                return;
            }

            Notification::make()
                ->title(__('AI SEO Improvement Unavailable'))
                ->body(__('Automatic SEO improvement is not available in this application.'))
                ->warning()
                ->send();
        } catch (\Throwable $e) {
            Log::error('[HandlesAiSeoImprovement] improveSeoForLocale failed', [
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);

            Notification::make()
                ->title(__('Failed to improve SEO'))
                ->body(__('An error occurred while queuing the SEO improvement. Please try again.'))
                ->danger()
                ->send();
        }
    }
}
