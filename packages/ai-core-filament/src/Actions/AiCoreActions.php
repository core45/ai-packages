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
 *             ...AiCoreActions::forPostsTableToolbar(),
 *         ]),
 *     ])
 *
 * Each factory is named after the SLOT it fills, not after a generic
 * "table"/"form", so the call site tells you where it belongs.
 */
class AiCoreActions
{
    /**
     * ai-core's share of the bulk actions on a posts table toolbar.
     *
     * @return list<BulkAction>
     */
    public static function forPostsTableToolbar(): array
    {
        return [
            RunSimilarityAuditAction::makeBulkAction('post'),
            CheckAiDetectionAction::makeBulkAction(),
        ];
    }
}
