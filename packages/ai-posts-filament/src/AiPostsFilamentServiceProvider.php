<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament;

use Filament\Panel;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the ai-posts Filament UI (the blog pipeline resource, its
 * management page, and the rewrite/SEO/photo actions) on the consuming app's
 * panels.
 *
 * Unlike `AiCoreFilamentServiceProvider`, this provider has no notification
 * seam to rebind — `ai-core-filament` already installed
 * `FilamentOperatorNotifier` over `NotifiesOperator`, and `ai-posts` reuses
 * that binding rather than defining its own. This provider's only job is
 * self-registering `AiPostsPlugin`.
 */
class AiPostsFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->registerPlugin();
    }

    /**
     * Self-register the plugin on the consuming app's panels.
     *
     * `Panel::configureUsing()` must run in `register()`, not `boot()`:
     * panels are constructed by their own providers, which may boot before
     * this one does.
     *
     * `config('ai.filament.panels')` selects which panels get it, mirroring
     * `ai-core-filament`'s three-way semantics so the two plugins are always
     * configured consistently from one config key:
     *
     *   null (default) — every panel, which is what a single-panel app wants
     *   ['admin']      — only the named panels
     *   []             — none; the app registers the plugin by hand
     *
     * An empty array is honoured as "none" rather than treated as unset. A
     * consumer who explicitly opts out must not silently get the UI anyway.
     */
    private function registerPlugin(): void
    {
        Panel::configureUsing(function (Panel $panel): void {
            $panels = config('ai.filament.panels');

            if (is_array($panels) && !in_array($panel->getId(), $panels, true)) {
                return;
            }

            $panel->plugin(AiPostsPlugin::make());
        });
    }
}
