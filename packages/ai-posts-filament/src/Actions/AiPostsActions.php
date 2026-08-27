<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Actions;

use Core45\AiCore\Filament\Actions\CheckAiDetectionAction;
use Core45\AiCore\Filament\Actions\CheckContentSimilarityAction;
use Core45\AiCore\Filament\Actions\ImproveSeoAction;
use Core45\AiCore\Filament\Actions\RunSeoAnalysisAction;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;

/**
 * Opt-in surface for the AI actions that belong on a blog post's Filament
 * screens.
 *
 * A package CANNOT inject actions into a host-owned Filament resource:
 * `Table::make()` runs `$static->configure()` — and therefore any
 * `Table::configureUsing()` callback a package registers — BEFORE
 * `Resource::configureTable()` calls `static::table($table)`, and
 * `HasRecordActions::recordActions()` opens by resetting
 * `$this->recordActions = []`. Anything a package pushed in is wiped. So the
 * host opts in with a single spread per slot instead.
 *
 * Each factory is named after the SLOT it fills, not after a generic
 * "table"/"form", so the call site tells you where it belongs.
 */
class AiPostsActions
{
    /**
     * ai-posts' share of the bulk actions on a posts table toolbar.
     *
     * Spread this BEFORE `AiCoreActions::forTableToolbar('post')`.
     *
     * The name stays blog-specific here but does not in `AiCoreActions`: this
     * package IS the blog, so knowing about posts is its job. `ai-core` is the
     * domain-neutral foundation, so the same factory there takes the registry
     * key as an argument.
     *
     * @return list<BulkAction>
     */
    public static function forPostsTableToolbar(): array
    {
        return [
            AiRewriteContentAction::makeBulkAction(),
        ];
    }

    /**
     * Every AI header action on the post edit page, in on-screen order.
     *
     * Deliberately ONE factory rather than an ai-core spread plus an
     * ai-posts spread: the actions interleave (core, core, core, posts,
     * core, core, posts), so two spreads would silently regroup them and
     * change the on-screen order. ai-posts already depends on ai-core, and
     * "the post edit header" is an ai-posts concern, so composing the whole
     * run here is both order-exact and the honest owner.
     *
     * @return list<Action>
     */
    public static function forPostEditHeader(): array
    {
        return [
            ManagePostPipelineAction::make(),
            CheckContentSimilarityAction::make(),
            CheckAiDetectionAction::make(),
            CheckAiDetectionAction::makeViewResults(),
            EnrichInternalLinksAction::make(),
            RunSeoAnalysisAction::make(),
            ImproveSeoAction::make(),
            GeneratePostPhotosAction::make(),
        ];
    }
}
