<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Actions;

use Core45\AiCore\Models\Concerns\HasAiDetection;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * NOTE: The source application dispatched its own AI-detection job to
 * submit content for AI detection. That job is not part of `core45/ai-core`
 * or `core45/ai-posts` and does not exist in this application, so the
 * submission flow ({@see self::make()} and {@see self::makeBulkAction()})
 * has been stripped down to a "not available" notice. Viewing already
 * recorded detection results ({@see self::makeViewResults()}) still works,
 * since it only relies on `Core45\AiCore\Models\Concerns\HasAiDetection`.
 */
class CheckAiDetectionAction
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
        return Action::make($name ?? 'checkAiDetection')
            ->label(__('Check for AI'))
            ->icon(Heroicon::OutlinedCpuChip)
            ->color('info')
            ->visible(fn (): bool => (bool) config('ai.assistant.ai_detection.enabled', false))
            ->requiresConfirmation()
            ->modalDescription(__('This will submit the content for AI detection analysis in all languages. The process runs in the background.'))
            ->action(function (Model $record): void {
                if (!in_array(HasAiDetection::class, class_uses_recursive($record), true)) {
                    Notification::make()
                        ->title(__('Error'))
                        ->body(__('This model does not support AI detection.'))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('AI Detection Unavailable'))
                    ->body(__('AI detection submission is not available in this application.'))
                    ->warning()
                    ->send();
            });
    }

    public static function makeBulkAction(?string $name = null): BulkAction
    {
        return BulkAction::make($name ?? 'bulkCheckAiDetection')
            ->label(__('Check for AI'))
            ->icon(Heroicon::OutlinedCpuChip)
            ->color('info')
            ->visible(fn (): bool => (bool) config('ai.assistant.ai_detection.enabled', false))
            ->requiresConfirmation()
            ->modalHeading(__('Check for AI Content'))
            ->modalDescription(__('This will submit selected records for AI detection analysis in all languages. The process runs in the background.'))
            ->modalSubmitActionLabel(__('Start AI Detection'))
            ->action(function (Collection $records): void {
                Notification::make()
                    ->title(__('AI Detection Unavailable'))
                    ->body(__('AI detection submission is not available in this application.'))
                    ->warning()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function makeViewResults(?string $name = null): Action
    {
        return Action::make($name ?? 'viewAiDetectionResults')
            ->label(__('AI Detection Results'))
            ->icon(Heroicon::OutlinedCpuChip)
            ->color('info')
            ->visible(function (Model $record): bool {
                if (!(bool) config('ai.assistant.ai_detection.enabled', false)) {
                    return false;
                }

                if (!in_array(HasAiDetection::class, class_uses_recursive($record), true)) {
                    return false;
                }

                return $record->aiDetections()->where('status', 'done')->exists(); // @phpstan-ignore method.notFound
            })
            ->modalHeading(__('AI Detection Results'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'))
            ->modalContent(function (Model $record): View {
                $locales = self::getConfiguredLocales();

                $detections = [];
                foreach ($locales as $locale) {
                    $detection = $record->latestAiDetection($locale); // @phpstan-ignore method.notFound
                    if ($detection !== null) {
                        $detections[$locale] = $detection;
                    }
                }

                return view('ai-core::components.ai-detection-results', [
                    'detections' => $detections,
                ]);
            });
    }
}
