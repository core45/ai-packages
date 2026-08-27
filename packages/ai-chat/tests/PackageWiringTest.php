<?php

declare(strict_types=1);

namespace Core45\AiChat\Tests;

use Core45\AiChat\AiChatServiceProvider;
use Core45\AiChat\Contracts\ChatProductCardContract;
use Core45\AiChat\Contracts\CustomerContextContract;
use Core45\AiChat\Livewire\CustomerAIChatPanel;
use Core45\AiChat\Services\CustomerAIService;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;

/**
 * The regression this file exists for: the package provider used to bind its
 * own seams to HOST classes, and one of those binds named a class that did not
 * exist. Nothing caught it, because every host test resolved the concrete
 * class instead of the contract. These tests boot the package the way a second
 * consuming application would — with no `App\` namespace present at all.
 */
class PackageWiringTest extends TestCase
{
    #[Test]
    public function the_package_boots_with_none_of_its_own_code_importing_the_host(): void
    {
        $this->assertTrue($this->app->providerIsLoaded(AiChatServiceProvider::class));

        // The autoloader in this monorepo still maps `App\`, so class_exists()
        // proves nothing. Assert on the package's own source instead: no file
        // under src/ may import the host application.
        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/^use\s+App\\\\/m', (string) file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'ai-chat must not import the host application: '.implode(', ', $offenders));
    }

    #[Test]
    public function the_customer_chat_livewire_component_keeps_its_pre_extraction_tag(): void
    {
        $this->assertInstanceOf(
            CustomerAIChatPanel::class,
            Livewire::new('front.customer-a-i-chat-panel'),
        );
    }

    #[Test]
    public function the_package_view_namespace_is_registered(): void
    {
        $this->assertTrue(view()->exists('ai-chat::livewire.front.customer-a-i-chat-panel'));
    }

    #[Test]
    public function the_seams_carry_only_a_null_default_the_consumer_can_still_override(): void
    {
        // THIS TEST SUPERSEDES A RULE, and says so because the rule lived in
        // exactly one place — the comment this replaced — and nowhere else. A
        // silent override would erase every trace that it was ever held.
        // Verbatim, it said:
        //
        //     "The package deliberately ships NO default implementation for
        //      either contract: binding a host class inside the package is what
        //      broke the D8/D9 inversion. An unbound seam must fail loudly, not
        //      silently resolve something wrong."
        //
        // The first half stands, and is still enforced below: a package default
        // must never be a class that assumes a host's product or customer
        // model. A package-owned null object is not that.
        //
        // The second half — "an unbound seam must fail loudly" — is what
        // changed, and only after tracing what the silence actually costs here.
        // `CustomerContextContract` is injected into `CustomerAIService` and
        // never read, so an empty context cannot reach a prompt: no price is
        // quoted, no order is denied. `ChatProductCardContract` returning `[]`
        // reads as "no cards", and the Blade gates on `!empty(...)`, so no
        // unvalidated product data reaches the DOM. Neither resolves something
        // *wrong*; they resolve nothing, visibly.
        //
        // Where that trade does NOT hold, the rule was kept rather than
        // superseded — see `Core45\AiCore\Contracts\UnconfiguredSeam` for the
        // link resolver, and ai-core's guest-token issuer, which throws with an
        // explanation instead of fabricating a credential.
        //
        // One cosmetic defect the null default does leave, recorded rather than
        // hidden: the panel still emits `type => 'product_recommendations'` with
        // an empty list beside "here are my recommendations", so a customer is
        // shown nothing under a heading promising something. Worth fixing; not a
        // reason to restore the fatal.
        //
        // FreshInstallBindingsTest covers the regression itself — resolving
        // either contract with nothing bound used to throw
        // `BindingResolutionException` on the ordinary chat path.
        foreach ([CustomerContextContract::class, ChatProductCardContract::class] as $contract) {
            $this->assertTrue(interface_exists($contract));
            $this->assertTrue($this->app->bound($contract), "{$contract} should carry a package-supplied null default.");

            // `bindIf`, not `bind`: a consumer binding still wins.
            $hostImplementation = new class implements ChatProductCardContract, CustomerContextContract
            {
                public function getCustomerContext(object $customer): array
                {
                    return ['host' => true];
                }

                public function getCustomerOrders(object $customer): Collection
                {
                    return collect();
                }

                public function getCustomerVouchers(): Collection
                {
                    return collect();
                }

                public function getAccountInfo(object $customer): array
                {
                    return [];
                }

                public function formatContextForPrompt(object $customer): string
                {
                    return '';
                }

                public function getPersonalizedPrice(object $product, ?object $customer): array
                {
                    return [];
                }

                public function validateCards(array $products): array
                {
                    return $products;
                }
            };

            $this->app->bind($contract, fn () => $hostImplementation);
            $this->assertSame($hostImplementation, $this->app->make($contract));
        }
    }

    #[Test]
    public function the_customer_service_depends_on_the_context_contract_not_a_concrete_host_class(): void
    {
        $parameters = (new \ReflectionMethod(CustomerAIService::class, '__construct'))->getParameters();
        $types = array_map(
            fn (\ReflectionParameter $parameter): ?string => $parameter->getType()?->__toString(),
            $parameters,
        );

        $this->assertContains(CustomerContextContract::class, $types);

        // With nothing bound by the consumer, resolving now succeeds against
        // the package's own null default (see FreshInstallBindingsTest) rather
        // than throwing — but it must still resolve the CONTRACT type-hint,
        // never a concrete host class the package has no business knowing
        // about.
        $service = $this->app->make(CustomerAIService::class);
        $this->assertInstanceOf(CustomerAIService::class, $service);
    }
}
