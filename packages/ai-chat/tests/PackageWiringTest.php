<?php

declare(strict_types=1);

namespace Core45\AiChat\Tests;

use Core45\AiChat\AiChatServiceProvider;
use Core45\AiChat\Contracts\ChatProductCardContract;
use Core45\AiChat\Contracts\CustomerContextContract;
use Core45\AiChat\Livewire\CustomerAIChatPanel;
use Core45\AiChat\Services\CustomerAIService;
use Illuminate\Contracts\Container\BindingResolutionException;
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
    public function the_seams_are_left_for_the_consumer_to_bind(): void
    {
        // The package deliberately ships NO default implementation for either
        // contract: binding a host class inside the package is what broke the
        // D8/D9 inversion. An unbound seam must fail loudly, not silently
        // resolve something wrong.
        foreach ([CustomerContextContract::class, ChatProductCardContract::class] as $contract) {
            $this->assertTrue(interface_exists($contract));
            $this->assertFalse($this->app->bound($contract), "{$contract} must be bound by the consumer, not the package.");
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

        // And with nothing bound, resolving it must fail on the CONTRACT — the
        // consumer's missing binding — rather than on a missing host class.
        $this->expectException(BindingResolutionException::class);
        $this->app->make(CustomerAIService::class);
    }
}
