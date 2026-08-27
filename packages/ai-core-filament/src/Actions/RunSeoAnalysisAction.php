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
 * NOTE: The source application dispatched its own SEO-analysis job to run
 * SEO analysis against a `SeoIndexable` record. That job is not part of
 * `core45/ai-core` or `core45/ai-posts` and does not exist in this
 * application, so the analysis dispatch has been stripped down to a
 * "not available" notice.
 */
class RunSeoAnalysisAction
{
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'runSeoAnalysis')
            ->label(__('Run SEO Analysis'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('This will queue a new SEO analysis for this content in all languages.'))
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
                    ->title(__('SEO Analysis Unavailable'))
                    ->body(__('SEO analysis is not available in this application.'))
                    ->warning()
                    ->send();
            });
    }

    public static function makeTableAction(?string $name = null): Action
    {
        return Action::make($name ?? 'runSeoAnalysis')
            ->label(__('Analyze SEO'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('info')
            ->action(function (Model $record): void {
                if (!method_exists($record, 'getOrCreateSeoIndexable')) {
                    return;
                }

                Notification::make()
                    ->title(__('SEO Analysis Unavailable'))
                    ->body(__('SEO analysis is not available in this application.'))
                    ->warning()
                    ->send();
            });
    }

    public static function makeBulkAction(?string $name = null): BulkAction
    {
        return BulkAction::make($name ?? 'bulkRunSeoAnalysis')
            ->label(__('Analyze SEO'))
            ->icon(Heroicon::OutlinedMagnifyingGlass)
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('This will queue SEO analysis for all selected records in all languages.'))
            ->action(function (Collection $records): void {
                Notification::make()
                    ->title(__('SEO Analysis Unavailable'))
                    ->body(__('SEO analysis is not available in this application.'))
                    ->warning()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
