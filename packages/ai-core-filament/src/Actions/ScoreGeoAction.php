<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Actions;

use Core45\AiCore\ModelRegistry;
use Core45\AiCore\Models\Concerns\HasGeoProfile;
use Core45\AiCore\Services\Geo\GeoScorerService;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;

/**
 * Re-scores GEO for a selection of records, in every configured locale.
 *
 * D7: moved out of a consuming application's own Filament table, where it was
 * an inline `BulkAction::make('scoreGeo')` duplicated across four resources.
 * GEO scoring is model-agnostic — {@see GeoScorerService} reads a flat array the
 * model returns from `geoProfileData()` and knows nothing about what it is
 * scoring — so one parameterised factory serves every content table instead of
 * four copies that drift.
 *
 * Runs INLINE, not queued, exactly as the source did. A locale sweep over a
 * large selection is therefore slow; that is pre-existing behaviour and moving
 * it to a job is a separate change, not part of this port.
 */
class ScoreGeoAction
{
    /**
     * @param  string  $modelKey  key registered in {@see ModelRegistry} (e.g. 'post')
     */
    public static function makeBulkAction(string $modelKey, ?string $name = null): BulkAction
    {
        return BulkAction::make($name ?? 'scoreGeo')
            ->label(__('Score GEO'))
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->action(function (Collection $records) use ($modelKey): void {
                // The key is not otherwise needed — the records are already
                // model instances — but resolving it turns a table wired with a
                // key the host never registered into a message naming that key,
                // instead of a scoring run that silently does the right thing
                // here and the wrong thing in the sibling actions that do use
                // the class. Same accessor and same reason as
                // RunSimilarityAuditAction.
                app(ModelRegistry::class)->getModelClassOrFail($modelKey);

                $locales = array_keys(config('laravellocalization.supportedLocales', []));

                foreach ($records as $record) {
                    // Capability check, not a class check — this action is
                    // parameterised by registry key precisely so it can serve a
                    // model no package knows the name of. Calling `scoreGeo()`
                    // rather than the scorer directly keeps a host's override of
                    // it in play; a table whose model never took the trait says
                    // so here instead of fataling on an undefined method.
                    if (!method_exists($record, 'scoreGeo')) {
                        throw new InvalidArgumentException(sprintf(
                            '%s cannot be GEO-scored: it must use %s.',
                            $record::class,
                            HasGeoProfile::class,
                        ));
                    }

                    foreach ($locales as $locale) {
                        $record->scoreGeo($locale);
                    }
                }

                Notification::make()->title(__('GEO scores updated'))->success()->send();
            })
            ->deselectRecordsAfterCompletion();
    }
}
