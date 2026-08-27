<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament;

use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Filament\Notifications\FilamentOperatorNotifier;
use Filament\Panel;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the ai-core Filament UI and upgrades operator notifications from
 * `ai-core`'s logging default to real panel notifications.
 *
 * Everything Filament-specific about `ai-core` lives in this package, so
 * `core45/ai-core` itself installs into an app with no Filament — or one on a
 * different panel — without pulling a UI it cannot use.
 */
class AiCoreFilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `bind`, not `bindIf`: `ai-core` registers first and has already bound
        // its logging fallback, so binding conditionally here would lose to it
        // and installing this package would silently change nothing. An app that
        // wants its own implementation binds it in its own provider, which
        // registers after both packages and therefore still wins.
        $this->app->bind(NotifiesOperator::class, FilamentOperatorNotifier::class);

        $this->registerPlugin();
    }

    public function boot(): void
    {
        // The blade files behind the SEO and analysis form components, reachable
        // as `view('ai-core::forms.components.…')`. They moved here with the
        // classes that render them: every one is referenced only by a Filament
        // component, so shipping them from the domain package would put blade
        // views in a package that has no UI.
        //
        // The namespace stays `ai-core` so no view reference needed rewriting.
        // Laravel resolves view and translation namespaces from separate
        // registries, so this coexists with `ai-core`'s translation namespace of
        // the same name.
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'ai-core');
    }

    /**
     * Self-register the plugin on the consuming app's panels.
     *
     * This moved here from `AiCoreServiceProvider` when the UI was split out.
     * `Panel::configureUsing()` must run in `register()`, not `boot()`: panels
     * are constructed by their own providers, which may boot before this one.
     *
     * `config('ai.filament.panels')` selects which panels get it:
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

            $panel->plugin(AiCorePlugin::make());
        });
    }
}
