<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Actions;

use Core45\AiCore\Jobs\RunSimilarityAudit;
use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Models\SimilarityAuditBatch;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Queues a similarity audit for a selection of records.
 *
 * Moved out of a consuming application's own Filament table so the audit
 * flow (batch creation + job dispatch + notification) lives with the
 * `RunSimilarityAudit` job that owns it.
 */
class RunSimilarityAuditAction
{
    /**
     * @param  string  $modelKey  key registered in {@see ModelRegistry} (e.g. 'post')
     */
    public static function makeBulkAction(string $modelKey, ?string $name = null): BulkAction
    {
        return BulkAction::make($name ?? 'run_similarity_audit')
            ->label(__('Run Similarity Audit'))
            ->icon(Heroicon::OutlinedMagnifyingGlassCircle)
            ->color('info')
            ->visible(fn (): bool => (bool) config('ai.assistant.enabled', false))
            ->requiresConfirmation()
            ->modalHeading(__('Similarity Audit'))
            ->modalDescription(__('This will analyze selected posts for content similarity against other content. The audit runs in the background and results will be available in the Similarity Audits section.'))
            ->modalSubmitActionLabel(__('Start Audit'))
            ->action(function (Collection $records) use ($modelKey): void {
                /** @var int $userId */
                $userId = Auth::id();

                // getModelClassOrFail() is the class-string-typed accessor;
                // getModelClass() returns a plain nullable string and loses
                // that guarantee for SimilarityAuditBatch::createForModel() below.
                $modelClass = app(ModelRegistry::class)->getModelClassOrFail($modelKey);

                $batch = SimilarityAuditBatch::createForModel(
                    $modelClass,
                    $records->count(),
                    $userId,
                    ['item_ids' => $records->pluck('id')->toArray()]
                );

                RunSimilarityAudit::dispatch($batch);

                Notification::make()
                    ->title(__('Similarity Audit Started'))
                    ->body(__(':count posts queued for similarity analysis. View results in AI Content → Similarity Audits.', [
                        'count' => $records->count(),
                    ]))
                    ->info()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
