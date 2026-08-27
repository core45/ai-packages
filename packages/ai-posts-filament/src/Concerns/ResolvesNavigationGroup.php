<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Concerns;

use UnitEnum;

/**
 * One navigation group for every resource this package registers.
 *
 * Before this, the package read two different config keys: the pipeline-run
 * resource used `blog-pipeline.navigation_group` (defaulting to "Blog Topics")
 * while the four trees moved out of the host used
 * `ai.filament.navigation_group` (defaulting to "AI"). A consumer setting either
 * one got a navigation split across two groups with no way to tell which key
 * governed which resource.
 *
 * The chain is deliberate. `ai-posts.filament.navigation_group` lets an app give
 * the blog UI its own group; leaving it unset falls back to the ai-core key, so
 * an app that has already named one AI group gets everything under it; and the
 * final default keeps a fresh install from landing resources at the top level.
 *
 * Filament accepts a plain string or an enum case implementing its `HasLabel`,
 * so both pass through — but anything else would break navigation rendering, and
 * a bad config value should not do that.
 */
trait ResolvesNavigationGroup
{
    public static function resolveNavigationGroup(): string|UnitEnum|null
    {
        $group = config('ai-posts.filament.navigation_group')
            ?? config('ai.filament.navigation_group', 'AI');

        return is_string($group) || $group instanceof UnitEnum ? $group : 'AI';
    }
}
