<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Contracts\EnrichesCitations;
use Core45\AiCore\Contracts\GeneratesImages;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Contracts\StoresFocusKeyphrases;
use Core45\AiCore\Support\Geo\NullCitationEnricher;
use Core45\AiCore\Support\Notifications\LoggingOperatorNotifier;
use Core45\AiCore\Support\Seo\NullFocusKeyphraseStore;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\Attributes\Test;

/**
 * What `composer require` gives you, before the host wires anything.
 *
 * This is the test the packages did not have, and its absence is why two
 * container errors sat in the rewrite pipeline unnoticed. Every existing suite
 * either runs inside the host application, which binds 21 contracts in its own
 * service provider, or exercises a class directly with its collaborators passed
 * in. Neither asks the question a new adopter asks first: *I installed it and
 * changed nothing — does it run?*
 *
 * The Testbench app here binds nothing of its own, so resolving through the
 * container is exactly what a fresh install does.
 *
 * Scope, stated so this test is not read as more than it is: it proves the
 * container is satisfiable, not that the packages are useful with no
 * configuration. `ModelRegistry` still needs `config('ai.models')` and
 * `RemoteAiClient` still needs credentials. Those fail loudly and name what is
 * missing. A `BindingResolutionException` deep inside a queued job does not.
 */
class FreshInstallBindingsTest extends TestCase
{
    /**
     * Every interface the rewrite pipeline resolves unconditionally must have a
     * default.
     *
     * "Unconditionally" is the operative word. An interface resolved only
     * behind an opt-in — `ChecksContentSimilarity`, reached solely via
     * `withSimilarityChecking()` — may legitimately have no default, because
     * asking for it is already a statement that you have one. These four are
     * reached on the ordinary path with no opting in at all.
     */
    #[Test]
    public function the_pipeline_contracts_resolve_with_nothing_bound_by_the_host(): void
    {
        $this->assertInstanceOf(NullFocusKeyphraseStore::class, app(StoresFocusKeyphrases::class));
        $this->assertInstanceOf(NullCitationEnricher::class, app(EnrichesCitations::class));
        $this->assertInstanceOf(LoggingOperatorNotifier::class, app(NotifiesOperator::class));
        $this->assertInstanceOf(GeneratesImages::class, app(GeneratesImages::class));
    }

    /**
     * The defaults must answer "no" to their capability probe.
     *
     * Both interfaces are a probe plus an action, and the pipeline calls the
     * probe first. A default that answered `true` would pass the test above and
     * still break the install — the pipeline would go on to call `save()` or
     * `enrich()` and get silence where it expected work. Returning false is
     * what makes these null objects correct rather than merely present.
     */
    #[Test]
    public function the_defaults_report_that_they_support_nothing(): void
    {
        $model = new class extends Model {};

        $this->assertFalse(app(StoresFocusKeyphrases::class)->supports($model));
        $this->assertFalse(app(EnrichesCitations::class)->supports($model));
    }

    /**
     * A host binding must win over the default.
     *
     * `bindIf`, not `bind`. If these were `bind`, adding a default would take
     * the feature away from every application that already had one — a fix that
     * breaks the installs that were working.
     */
    #[Test]
    public function a_host_binding_overrides_the_default(): void
    {
        $hostEnricher = new class implements EnrichesCitations
        {
            public function supports(Model $model): bool
            {
                return true;
            }

            public function enrich(Model $model, string $locale): void {}
        };

        $this->app->bind(EnrichesCitations::class, fn (): EnrichesCitations => $hostEnricher);

        $this->assertSame($hostEnricher, app(EnrichesCitations::class));
        $this->assertNotInstanceOf(NullCitationEnricher::class, app(EnrichesCitations::class));
    }
}
