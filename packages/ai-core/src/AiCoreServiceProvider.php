<?php

declare(strict_types=1);

namespace Core45\AiCore;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiCore\Support\Notifications\LoggingOperatorNotifier;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Binds `AiClient` unconditionally to `RemoteAiClient` — every AI surface is
 * served by the phasea service; no local driver or per-surface override
 * exists (the `local` rollback is retired, cutover safety is the documented
 * git-revert runbook).
 *
 * CRITICAL (Pitfall 1): bound with `scoped()` lifecycle — a request/job-wide
 * shared instance, re-resolved fresh at the start of every request or queued
 * job. Horizon workers are long-running processes; an app-wide-shared binding
 * would be resolved once by the first job a worker picks up and reused for
 * every subsequent job on that worker, silently ignoring later config
 * changes (RemoteAiClient reads its base URL and token at construction).
 * `scoped()` avoids that by re-resolving on every request/job lifecycle
 * boundary.
 *
 * Per-surface resolution lives in `AiClientFactory`, also bound scoped
 * here for consistency.
 *
 * The `ModelRegistry` singleton is built from `config('ai.models')`,
 * `config('ai.url_slugs')` and `config('ai.seo_content_types')`. Consuming apps
 * register their models by editing that config file; re-binding the singleton
 * in a later provider still works but is no longer necessary.
 */
class AiCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // D6: package-owned config defaults. The consumer app's config/ai.php
        // (if present) overrides these values key-by-key; env var names are
        // stable across the extraction.
        $this->mergeConfigFrom(__DIR__.'/../config/ai.php', 'ai');

        // Phase 12 (CUT-06/07 final): the `local` rollback is retired. The default
        // AiClient always resolves RemoteAiClient; no per-surface local adapter remains.
        $this->app->scoped(AiClient::class, fn (Application $app) => $app->make(RemoteAiClient::class));

        $this->app->scoped(AiClientFactory::class);

        // D1/A4: the package owns the LOOKUP LOGIC, the consumer supplies the
        // MAP — as config, not as code. A drop-in install registers its models
        // by editing `config/ai.php` and touching no provider. An app that has
        // not configured any yet gets an empty registry rather than a fatal.
        $this->app->scoped(ModelRegistry::class, fn () => new ModelRegistry(
            models: config('ai.models', []),
            urlSlugs: config('ai.url_slugs', []),
            seoContentTypes: config('ai.seo_content_types', []),
        ));

        // D22: the jobs report progress to the operator who started them. That
        // used to be a direct `Filament\Notifications\Notification::make()`
        // call in 13 places, which made a Filament panel a hard requirement of
        // packages that have no UI of their own.
        //
        // `bindIf`, not `bind`: an app that has already chosen an implementation
        // keeps it. `ai-core-filament` registers later and binds unconditionally,
        // so installing it upgrades delivery from the log to a real panel
        // notification without the app configuring anything.
        $this->app->bindIf(NotifiesOperator::class, LoggingOperatorNotifier::class);
    }

    public function boot(): void
    {
        // D5: package migrations keep their original filenames so existing
        // installs (where they already ran under the host's database/migrations)
        // match their `migrations` ledger rows and never re-run; fresh installs
        // discover them here for the first time.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            // D6/D11: `mergeConfigFrom` above already supplies working defaults,
            // so publishing is only for apps that want to edit the file. This
            // app has its own `config/ai.php` and does NOT publish.
            $this->publishes([
                __DIR__.'/../config/ai.php' => config_path('ai.php'),
            ], 'ai-core-config');

            // For apps that prefer migrations in their own tree. Publishing is
            // NOT the upgrade path for an existing install — see README.
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ai-core-migrations');
        }
    }
}
