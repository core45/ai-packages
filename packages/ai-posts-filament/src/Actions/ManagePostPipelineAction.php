<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Actions;

use Core45\AiPosts\Filament\Pages\ManagePostPipeline;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

/**
 * Plain navigation link to {@see ManagePostPipeline}.
 *
 * That page used to be reachable through the host resource's
 * `getRecordSubNavigation()`. It is now a standalone `Filament\Pages\Page`,
 * which cannot be listed there, so without this link the AI-generation page
 * has a working route and no way to reach it.
 *
 * The URL is resolved through the page class itself (never a hardcoded
 * route name or path) so the package stays self-contained: `getUrl()` walks
 * the panel the page is registered on and derives the route for us.
 */
class ManagePostPipelineAction
{
    public static function make(?string $name = null): Action
    {
        return Action::make($name ?? 'managePostPipeline')
            ->label(__('AI Generation'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('warning')
            ->url(fn (Model $record): string => ManagePostPipeline::getUrl(['record' => $record->getKey()]));
    }
}
