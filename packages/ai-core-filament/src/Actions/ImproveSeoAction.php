<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Actions;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * NOTE: The source application resolved a host-owned SEO improvement service
 * and dispatched its own SEO-improvement job to automatically fix SEO
 * issues found by analysis. Neither class is part of `core45/ai-core` or
 * `core45/ai-posts`, and neither exists in this application, so the
 * improvement dispatch has been stripped down to a "not available" notice.
 */
class ImproveSeoAction
{
    /**
     * Check if AI assistant is enabled.
     */
    private static function isAiEnabled(): bool
    {
        return (bool) config('ai.assistant.enabled', false);
    }

    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'improveSeo')
            ->label(__('Improve SEO'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->visible(fn (): bool => self::isAiEnabled())
            ->requiresConfirmation()
            ->modalDescription(__('This will use AI to automatically improve SEO titles and descriptions based on the analysis issues found. The analysis will be re-run after improvements are applied.'))
            ->action(function (Model $record): void {
                if (!method_exists($record, 'getOrCreateSeoIndexable')) {
                    Notification::make()
                        ->title(__('Error'))
                        ->body(__('This model does not support SEO analysis.'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('SEO Improvement Unavailable'))
                    ->body(__('Automatic SEO improvement is not available in this application.'))
                    ->warning()
                    ->send();
            });
    }

    public static function makeTableAction(?string $name = null): Action
    {
        return Action::make($name ?? 'improveSeo')
            ->label(__('Improve SEO'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->visible(fn (): bool => self::isAiEnabled())
            ->action(function (Model $record): void {
                if (!method_exists($record, 'getOrCreateSeoIndexable')) {
                    return;
                }

                Notification::make()
                    ->title(__('SEO Improvement Unavailable'))
                    ->body(__('Automatic SEO improvement is not available in this application.'))
                    ->warning()
                    ->send();
            });
    }

    public static function makeBulkAction(?string $name = null): BulkAction
    {
        return BulkAction::make($name ?? 'bulkImproveSeo')
            ->label(__('Improve SEO'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->visible(fn (): bool => self::isAiEnabled())
            ->requiresConfirmation()
            ->modalDescription(__('This will use AI to improve SEO for all selected records in all languages where issues were found.'))
            ->action(function (Collection $records): void {
                Notification::make()
                    ->title(__('SEO Improvement Unavailable'))
                    ->body(__('Automatic SEO improvement is not available in this application.'))
                    ->warning()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
