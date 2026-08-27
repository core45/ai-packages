<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Actions;

use Filament\Actions\BulkAction;

/**
 * Opt-in surface for ai-core's Filament actions.
 *
 * A package CANNOT inject actions into a host-owned Filament resource:
 * `Table::make()` runs `$static->configure()` — and therefore any
 * `Table::configureUsing()` callback a package registers — BEFORE
 * `Resource::configureTable()` calls `static::table($table)`, and
 * `HasRecordActions::recordActions()` opens by resetting
 * `$this->recordActions = []`. Anything a package pushed in is wiped.
 *
 * So the host opts in with a single spread per slot instead:
 *
 *     ->toolbarActions([
 *         BulkActionGroup::make([
 *             ...AiCoreActions::forTableToolbar('post'),
 *         ]),
 *     ])
 *
 * Each factory is named after the SLOT it fills, not after a generic
 * "table"/"form", so the call site tells you where it belongs.
 */
class AiCoreActions
{
    /**
     * ai-core's share of the bulk actions on a content table's toolbar.
     *
     * This was `forPostsTableToolbar()`, with `'post'` hardcoded — blog
     * knowledge inside the package that is supposed to have none. A store with
     * no blog could not use it, and the name told a webinar table it was in the
     * wrong place. The actions themselves were always generic; only the name and
     * the key were not.
     *
     * D7 added `ScoreGeoAction` here. A host that still wires its own inline
     * `scoreGeo` bulk action will see TWO until it removes that copy — the
     * duplicate is the visible signal that the migration is half done, not a
     * bug in this factory.
     *
     * @param  string  $modelKey  a key registered in `config('ai.models')` naming
     *                            the records the table lists — 'post',
     *                            'product', 'webinar', or the consuming app's own
     * @return list<BulkAction>
     */
    public static function forTableToolbar(string $modelKey): array
    {
        return [
            RunSimilarityAuditAction::makeBulkAction($modelKey),
            ScoreGeoAction::makeBulkAction($modelKey),
            CheckAiDetectionAction::makeBulkAction(),
        ];
    }
}
