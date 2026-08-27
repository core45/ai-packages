<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament;

use Core45\AiPosts\Filament\Pages\ManagePostPipeline;
use Core45\AiPosts\Filament\Resources\BlogPostPipelineRuns\BlogPostPipelineRunResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

/**
 * Registers the ai-posts blog-pipeline Filament resources and pages on a panel.
 *
 * A consuming app normally gets this for free: `AiPostsFilamentServiceProvider`
 * self-registers it through `Panel::configureUsing()`. Registering it by hand
 * with `$panel->plugin(AiPostsPlugin::make())` is for apps that opted out by
 * setting `ai.filament.panels` to an empty array.
 *
 * The registering provider lives in THIS package, not in `ai-posts`. The domain
 * package has to stay loadable in an app with no Filament, so it can neither
 * name this class nor touch `Panel` — which is what the docblock here used to
 * claim it did.
 */
class AiPostsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'ai-posts';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            BlogPostPipelineRunResource::class,
        ]);

        $panel->pages([
            ManagePostPipeline::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
