<?php

declare(strict_types=1);

namespace Core45\AiCore;

use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Contracts\ChecksContentSimilarity;
use Core45\AiCore\Contracts\EnrichesCitations;
use Core45\AiCore\Contracts\GeneratesImages;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Contracts\IssuesGuestStreamTokens;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Contracts\ObservesAiContracts;
use Core45\AiCore\Contracts\ProvidesMandatoryWords;
use Core45\AiCore\Contracts\StoresFocusKeyphrases;
use Core45\AiCore\Models\Concerns\HasAiDetection;
use Core45\AiCore\Observers\AiDetectionObserver;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\ContentSimilarityService;
use Core45\AiCore\Services\ImageGeneration\FalAiService;
use Core45\AiCore\Services\ImageGeneration\GeminiImageService;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiCore\Support\Chat\UnconfiguredGuestStreamTokenIssuer;
use Core45\AiCore\Support\Content\NullMandatoryWordsProvider;
use Core45\AiCore\Support\Geo\NullCitationEnricher;
use Core45\AiCore\Support\Notifications\LoggingOperatorNotifier;
use Core45\AiCore\Support\Observability\NullContractObserver;
use Core45\AiCore\Support\PromptKeyRegistry;
use Core45\AiCore\Support\Seo\NullFocusKeyphraseStore;
use Core45\AiCore\Support\Slugs\StrSlugGenerator;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

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

        // D7: the GEO scorer reads `geo.site_name` and three `geo.scoring.*`
        // thresholds with no inline fallback, so the package has to supply them
        // or a fresh install scores differently to an existing one — see
        // config/geo.php for what each unset key actually does.
        //
        // `mergeConfigFrom` is NOT recursive — `Illuminate\Support\ServiceProvider`
        // does a plain `array_merge(require $path, $config->get($key, []))`. A
        // consuming app's top-level keys win, and its `models` list is untouched,
        // but an app that defines `geo.scoring` with ONE key replaces this whole
        // nested array and the siblings come back null. That is why every
        // `geo.*` read in GeoScorerService also carries an inline default; the
        // merge alone is not enough, and the failure is silent.
        $this->mergeConfigFrom(__DIR__.'/../config/geo.php', 'geo');

        // Phase 12 (CUT-06/07 final): the `local` rollback is retired. The default
        // AiClient always resolves RemoteAiClient; no per-surface local adapter remains.
        $this->app->scoped(AiClient::class, fn (Application $app) => $app->make(RemoteAiClient::class));

        $this->app->scoped(AiClientFactory::class);

        // D14: the prompt-key catalog is core-held but NOT core-authored. A
        // plain singleton, not `scoped()`: registration happens once per
        // process from each package's provider, and re-resolving it per job
        // would hand out an empty registry to every worker after the first.
        $this->app->singleton(PromptKeyRegistry::class);

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

        // `GeneratesImages` had two implementations in this package and no
        // binding for either, while `ai-posts-filament` resolves it in three
        // places. `app(GeneratesImages::class)` on an interface with no binding
        // throws "target is not instantiable" — so the AI photo action was a
        // container error waiting for the first person to open it, in every
        // application including this one.
        //
        // Which provider to use is a deployment choice, not a package one, so it
        // comes from config. `bindIf` leaves an app that binds its own alone.
        $this->app->bindIf(GeneratesImages::class, function (Application $app): GeneratesImages {
            $provider = config('ai.image_provider', 'fal');

            /** @var array<string, class-string<GeneratesImages>> $providers */
            $providers = [
                'fal' => FalAiService::class,
                'gemini' => GeminiImageService::class,
            ];

            if (!isset($providers[$provider])) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown ai.image_provider [%s]. Known providers: %s. Bind %s yourself to use another.',
                    is_scalar($provider) ? (string) $provider : gettype($provider),
                    implode(', ', array_keys($providers)),
                    GeneratesImages::class,
                ));
            }

            return $app->make($providers[$provider]);
        });

        // The same defect as `GeneratesImages` above, in two more places. Found
        // by asking what `composer require core45/ai-posts` actually does in an
        // application that is not this project's own host.
        //
        // `AbstractContentRewriteService::processFocusKeyphrases()` opens with
        // an unguarded `app(StoresFocusKeyphrases::class)` (line 530), and
        // `processModel()` resolves `EnrichesCitations` at line 450 — BEFORE
        // testing `config('geo.citation.enabled')` on the very next line, so
        // turning the feature off could not prevent the failure. Both run on
        // every rewrite, so with no binding a fresh install threw
        // `BindingResolutionException` on the first Post rewrite it attempted.
        // The citation one is the sharper case: `geo.citation.enabled` is not a
        // key this package's `config/geo.php` ships, so the phase would have
        // been skipped anyway. The install broke on a feature it was never
        // going to run.
        //
        // Both interfaces lead with a `supports()` capability probe, so "this
        // application does neither" is already expressible in the contract
        // itself. These null objects are that answer, not a stub standing in
        // for missing work. A host with a GEO service or keyphrase storage
        // binds its own and `bindIf` leaves it alone — the same upgrade path
        // `ai-core-filament` uses for `NotifiesOperator`.
        // The one default here that is NOT a no-op. `RemoteAiClient` resolves
        // this unguarded on the anonymous-chat path (lines 297 and 338), so it
        // has the same fatal shape as the others — but the value it returns is a
        // credential forwarded to the phasea service, and inventing one would
        // turn a missing binding into an authentication failure several systems
        // away from its cause. This binding exists only to replace
        // "Target [...] is not instantiable" with a message that says guest chat
        // triggered it and what to do about it.
        $this->app->bindIf(IssuesGuestStreamTokens::class, UnconfiguredGuestStreamTokenIssuer::class);

        $this->app->bindIf(StoresFocusKeyphrases::class, NullFocusKeyphraseStore::class);
        $this->app->bindIf(EnrichesCitations::class, NullCitationEnricher::class);

        // `AbstractContentRewriteService::generateUniqueSlug()` opens with an
        // unguarded `app(GeneratesSlugs::class)->make($baseName)` — the same
        // shape of defect as `StoresFocusKeyphrases` and `EnrichesCitations`
        // above, just without a `supports()` probe to answer "no" with.
        //
        // Unlike those two this gets a REAL implementation, not a null object:
        // there is no correct way to skip slug generation, and `Str::slug()` is
        // the obvious default every host would otherwise have to write for
        // itself. Uniqueness stays the caller's job — `generateUniqueSlug()`
        // already owns the locale-scoped retry loop that appends `-1`, `-2`, …
        // — so the bound implementation only has to turn a title into a slug
        // once. See `StrSlugGenerator` for the full reasoning.
        $this->app->bindIf(GeneratesSlugs::class, StrSlugGenerator::class);

        // `ChecksContentSimilarity` is reached from `withSimilarityChecking()`,
        // an explicit opt-in — and ALSO, contrary to what this comment said
        // until the security review checked it, as a plain constructor
        // dependency of `QualityGateService`, used unconditionally on every
        // module of every pipeline run. The binding is therefore more
        // necessary than "opt-in only" implied, not less. It gets a default
        // because,
        // unlike `EnrichesCitations` or `StoresFocusKeyphrases`, this package
        // already ships a working implementation with no host-specific
        // dependency: `ContentSimilarityService` does same-type Jaccard
        // comparison against the model's own table, nothing the host has to
        // supply. Binding it means opting in is enough — a host does not also
        // have to write a similarity checker just to turn the feature on.
        $this->app->bindIf(ChecksContentSimilarity::class, ContentSimilarityService::class);

        // `ProvidesMandatoryWords` and `ObservesAiContracts` have no call site
        // inside ai-core at all — both are resolved unconditionally (no
        // `bound()` guard, no try/catch) from `ai-posts`:
        // `DynamicContentModule` and two `*WriterService` classes call
        // `app(ProvidesMandatoryWords::class)->asProfileParameter()` on every
        // prompt payload, and `DynamicContentModule` calls
        // `app(ObservesAiContracts::class)->observeContract(...)` on every
        // module render. Because this provider is registered once for the
        // whole application container, binding the defaults here closes the
        // same `BindingResolutionException` for those call sites too, without
        // touching ai-posts.
        //
        // Both get null objects, for reasons documented on each class:
        // `NullMandatoryWordsProvider` returns `[]`, which the interface's own
        // docblock already names as "the store mandates nothing" — not a stand-in
        // for missing work. `NullContractObserver` is a no-op, which is the
        // strictest reading of `ObservesAiContracts`' own requirement that
        // implementations must never throw into a render path.
        $this->app->bindIf(ProvidesMandatoryWords::class, NullMandatoryWordsProvider::class);
        $this->app->bindIf(ObservesAiContracts::class, NullContractObserver::class);

        // Two seams get no usable default, for opposite reasons.
        //
        // `GeneratesContentMedia` is resolved from `ai-posts` and `ai-products`
        // only inside `processMediaGeneration()`, itself gated by
        // `isMediaGenerationEnabled()` (an opt-in config flag) AND wrapped in a
        // `catch (\Exception $e)` that logs and continues. Doubly guarded: a
        // host that has not turned media generation on never reaches the
        // resolution, and one that has turned it on without binding an
        // implementation gets a caught, logged error per attempt rather than a
        // crash. Enabling that config is already the host's statement that it
        // has an implementation, matching the `ChecksContentSimilarity` opt-in
        // reasoning above.
        //
        // `IssuesGuestStreamTokens` IS bound, above, but to
        // `UnconfiguredGuestStreamTokenIssuer`, which THROWS. That is not a
        // null object and is not meant to be usable; it exists only to replace
        // the container's `Target [...] is not instantiable` with a sentence
        // naming the feature and what to bind.
        //
        // It has to throw, because it IS reached unconditionally on a real path —
        // `RemoteAiClient::customerChat()` and `::streamCustomerChat()` resolve
        // it whenever the caller is a guest identity, with no `bound()` check
        // and no try/catch — but it has no sensible no-op. Token issuance is
        // session/auth-specific by design (see the contract's own docblock); a
        // fake or empty token would not skip the feature the way an empty list
        // or a `supports() === false` does, it would forward a broken
        // credential that phasea rejects, turning a clear
        // `BindingResolutionException` into an opaque upstream 401. A host that
        // serves guest chat has to bind its own issuer regardless; there is no
        // default that helps rather than hides the problem.
    }

    public function boot(): void
    {
        $this->registerOwnPromptKeys();

        // D5: package migrations keep their original filenames so existing
        // installs (where they already ran under the host's database/migrations)
        // match their `migrations` ledger rows and never re-run; fresh installs
        // discover them here for the first time.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        // The package's own strings, reachable as `__('ai-core::…')`. Laravel
        // keeps view and translation namespaces in separate registries, so
        // `ai-core-filament` registering the *view* namespace `ai-core` for the
        // blade files its form components render does not collide with this.
        //
        // Translations stay in the domain package even though only the Filament
        // resources read them today: they are plain strings, they need no panel,
        // and an app on a different UI can reuse them.
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'ai-core');

        $this->registerAiDetectionObserver();

        if ($this->app->runningInConsole()) {
            // D6/D11: `mergeConfigFrom` above already supplies working defaults,
            // so publishing is only for apps that want to edit the file. This
            // app has its own `config/ai.php` and does NOT publish.
            $this->publishes([
                __DIR__.'/../config/ai.php' => config_path('ai.php'),
                __DIR__.'/../config/geo.php' => config_path('geo.php'),
            ], 'ai-core-config');

            // For apps that prefer migrations in their own tree. Publishing is
            // NOT the upgrade path for an existing install — see README.
            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'ai-core-migrations');
        }
    }

    /**
     * Register the prompt keys `ai-core` itself owns — and only those.
     *
     * The seven here are the assistant and generic-content prompts served by
     * this package. Every `*_rewrite_system` / `*_seo_system` key belongs to
     * the domain package whose service supplies it via
     * `AbstractContentRewriteService::getPromptOverrideKey()`, and is
     * registered from that package's provider. Nothing in this method names a
     * domain, and the `PackagesHaveNoDomainKeysTest` boundary test keeps it
     * that way.
     *
     * Colours are carried over from the `match` that used to live on the model.
     * Note the one asymmetry that came with them: `content_generation` and
     * `seo_generation` are `warning`, and so is `product_rewrite_system` (now
     * registered by `ai-products`) — but no other rewrite or SEO key is. That
     * looks like drift rather than intent; it is preserved verbatim here so
     * this change moves the taxonomy without also silently restyling the admin
     * table. Worth a deliberate decision separately.
     */
    private function registerOwnPromptKeys(): void
    {
        $registry = $this->app->make(PromptKeyRegistry::class);

        $registry->registerMany([
            // Admin AI assistant.
            'admin_system' => ['label' => 'Admin System Prompt', 'color' => 'info'],
            'query_instructions' => ['label' => 'Query Instructions (data fetching)', 'color' => 'info'],
            'action_instructions' => ['label' => 'Action Instructions (data modification)', 'color' => 'info'],

            // Customer AI assistant.
            'customer_system' => ['label' => 'Customer System Prompt (authenticated)', 'color' => 'success'],
            'anonymous_system' => ['label' => 'Anonymous System Prompt (public)', 'color' => 'success'],

            // Generic content generation. No live surface routes these to a
            // phasea template, hence no `template` — the form preview falls
            // back to its placeholder, as it always did.
            'content_generation' => ['label' => 'Content Generation Prompt', 'color' => 'warning'],
            'seo_generation' => ['label' => 'SEO Generation Prompt', 'color' => 'warning'],
        ]);
    }

    /**
     * Attach `AiDetectionObserver` to every registered model that can use it.
     *
     * `HasAiContent`'s docblock has claimed this happens since the extraction,
     * and it did not: nothing in this provider called `observe()`, so the
     * packaged observer was dead code in every application. The reference host
     * did not notice because its own models carry
     * `#[ObservedBy(App\Observers\AiDetectionObserver::class)]` and run a host
     * copy — a drop-in install got the comment without the behaviour.
     *
     * Why it belongs here and not on the model: `Model::observe()` does
     * `new static`, which re-enters `bootIfNotBooted()` on a model that is
     * currently booting and throws a LogicException. A provider's `boot()` runs
     * outside any model's boot window, so the call is safe here. The host's
     * `#[ObservedBy]` attribute sidesteps the same problem differently, by
     * having Laravel resolve it outside that window.
     *
     * The whole thing is behind the same config flag the observer checks first,
     * which is what keeps it free: the flag defaults to false, so an app that
     * has not opted in never autoloads a single registered model class here.
     * Without that guard, `class_exists()` on every entry in `ai.models` would
     * autoload and boot all of them on every request.
     */
    private function registerAiDetectionObserver(): void
    {
        if (!(bool) config('ai.assistant.ai_detection.enabled', false)) {
            return;
        }

        /** @var array<string, class-string<Model>|mixed> $models */
        $models = config('ai.models', []);

        foreach ($models as $class) {
            if (!is_string($class) || !class_exists($class)) {
                continue;
            }

            if (!is_subclass_of($class, Model::class)) {
                continue;
            }

            // Registering the observer on a model without the trait would be
            // harmless — the observer re-checks — but skipping keeps the
            // dispatcher free of listeners that can only ever return early.
            if (!in_array(HasAiDetection::class, class_uses_recursive($class), true)) {
                continue;
            }

            $class::observe(AiDetectionObserver::class);
        }
    }
}
