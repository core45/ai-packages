<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\Contracts\ChecksContentSimilarity;
use Core45\AiCore\Contracts\EnrichesCitations;
use Core45\AiCore\Contracts\GeneratesImages;
use Core45\AiCore\Contracts\GeneratesSlugs;
use Core45\AiCore\Contracts\IssuesGuestStreamTokens;
use Core45\AiCore\Contracts\NotifiesOperator;
use Core45\AiCore\Contracts\ObservesAiContracts;
use Core45\AiCore\Contracts\ProvidesMandatoryWords;
use Core45\AiCore\Contracts\StoresFocusKeyphrases;
use Core45\AiCore\Services\ContentSimilarityService;
use Core45\AiCore\Support\Chat\UnconfiguredGuestStreamTokenIssuer;
use Core45\AiCore\Support\Content\NullMandatoryWordsProvider;
use Core45\AiCore\Support\Geo\NullCitationEnricher;
use Core45\AiCore\Support\Notifications\LoggingOperatorNotifier;
use Core45\AiCore\Support\Observability\NullContractObserver;
use Core45\AiCore\Support\Seo\NullFocusKeyphraseStore;
use Core45\AiCore\Support\Slugs\StrSlugGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

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
     * behind an opt-in or a guard — `GeneratesContentMedia`, gated by a config
     * flag AND a try/catch, and `IssuesGuestStreamTokens`, which has no
     * sensible no-op at all — may legitimately have no default, because asking
     * for it is already a statement that you have one, or because a fake
     * default would hide the problem rather than fix it. These are reached on
     * the ordinary path with no opting in and no guard at all — `GeneratesSlugs`
     * and `ChecksContentSimilarity` from ai-core's own rewrite pipeline,
     * `ProvidesMandatoryWords` and `ObservesAiContracts` from ai-posts call
     * sites that share this same container.
     */
    #[Test]
    public function the_pipeline_contracts_resolve_with_nothing_bound_by_the_host(): void
    {
        $this->assertInstanceOf(NullFocusKeyphraseStore::class, app(StoresFocusKeyphrases::class));
        $this->assertInstanceOf(NullCitationEnricher::class, app(EnrichesCitations::class));
        $this->assertInstanceOf(LoggingOperatorNotifier::class, app(NotifiesOperator::class));
        $this->assertInstanceOf(GeneratesImages::class, app(GeneratesImages::class));
        $this->assertInstanceOf(StrSlugGenerator::class, app(GeneratesSlugs::class));
        $this->assertInstanceOf(ContentSimilarityService::class, app(ChecksContentSimilarity::class));
        $this->assertInstanceOf(NullMandatoryWordsProvider::class, app(ProvidesMandatoryWords::class));
        $this->assertInstanceOf(NullContractObserver::class, app(ObservesAiContracts::class));
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
     * `StrSlugGenerator` turns a title into a slug and leaves uniqueness to the
     * caller — `AbstractContentRewriteService::generateUniqueSlug()` owns the
     * `-1`, `-2`, … retry loop, so the bound default must not attempt it.
     */
    #[Test]
    public function the_slug_default_slugifies_without_attempting_uniqueness(): void
    {
        $this->assertSame('hello-world', app(GeneratesSlugs::class)->make('Hello World'));
    }

    /**
     * `NullMandatoryWordsProvider` returns the interface's own documented
     * "the store mandates nothing" shape: an empty array, not
     * `['profile' => ['mandatory_words' => []]]`.
     */
    #[Test]
    public function the_mandatory_words_default_reports_no_words(): void
    {
        $this->assertSame([], app(ProvidesMandatoryWords::class)->asProfileParameter());
    }

    /**
     * `NullContractObserver::observeContract()` must not throw — the contract's
     * own docblock requires it, and callers rely on that (see
     * `DynamicContentModule`, which does not wrap the call).
     */
    #[Test]
    public function the_contract_observer_default_does_not_throw(): void
    {
        app(ObservesAiContracts::class)->observeContract('any.capability', 'any.surface');

        $this->addToAssertionCount(1);
    }

    /**
     * Guest chat resolves without a container error, and fails with a sentence.
     *
     * The odd one out. Every other default here does nothing quietly; this one
     * throws, because the value it would have to invent is a credential the AI
     * service will reject — turning a missing binding into an auth failure a
     * long way from its cause. The binding buys a message that names guest chat
     * and says what to bind, in place of "Target [...] is not instantiable".
     *
     * Asserting the message, not just the type: the message IS the feature.
     */
    #[Test]
    public function the_guest_token_default_explains_itself_instead_of_failing_in_the_container(): void
    {
        $issuer = app(IssuesGuestStreamTokens::class);

        $this->assertInstanceOf(UnconfiguredGuestStreamTokenIssuer::class, $issuer);

        try {
            $issuer->issue('guest:abc', 'conversation-1');
            $this->fail('The unconfigured issuer must throw rather than return a fabricated token.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Anonymous chat requires', $e->getMessage());
            $this->assertStringContainsString(IssuesGuestStreamTokens::class, $e->getMessage());
        }
    }
}
