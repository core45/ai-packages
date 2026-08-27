<?php

declare(strict_types=1);

namespace Core45\AiChat\Tests;

use Core45\AiChat\Contracts\ChatProductCardContract;
use Core45\AiChat\Contracts\CustomerContextContract;
use Core45\AiChat\Support\NullChatProductCardValidator;
use Core45\AiChat\Support\NullCustomerContext;
use PHPUnit\Framework\Attributes\Test;

/**
 * What `composer require core45/ai-chat` gives you, before the host wires
 * anything.
 *
 * Modelled on `ai-core`'s `FreshInstallBindingsTest`. The Testbench app here
 * (`TestCase::getPackageProviders()`) binds nothing beyond `ai-core` and
 * `ai-chat` — no `App\Providers\AiServiceProvider`, which is where this
 * app's own `ChatProductCardContract`/`CustomerContextContract`
 * implementations live — so resolving through the container is exactly what
 * a second consuming application experiences on day one.
 */
class FreshInstallBindingsTest extends TestCase
{
    /**
     * Both contracts are resolved on the ordinary customer-chat path with no
     * config flag or `bound()` check guarding either resolution —
     * `CustomerContextContract` via `CustomerAIChatPanel::mount()` (Livewire's
     * own lifecycle hook) building `CustomerAIService`, and
     * `ChatProductCardContract` via `validateProducts()` whenever the
     * assistant's reply parses as a product recommendation.
     */
    #[Test]
    public function the_chat_contracts_resolve_with_nothing_bound_by_the_host(): void
    {
        $this->assertInstanceOf(NullChatProductCardValidator::class, app(ChatProductCardContract::class));
        $this->assertInstanceOf(NullCustomerContext::class, app(CustomerContextContract::class));
    }

    /**
     * Neither default fabricates host data. `ChatProductCardContract`
     * validates nothing (no product to trust without a real product model to
     * check against) and `CustomerContextContract` reports an empty customer
     * (no order/voucher/pricing history to fabricate).
     */
    #[Test]
    public function the_defaults_report_nothing_rather_than_fabricating_host_data(): void
    {
        $this->assertSame([], app(ChatProductCardContract::class)->validateCards([
            ['id' => 1, 'name' => 'Trust me'],
        ]));

        $customer = new class {};

        $context = app(CustomerContextContract::class);
        $this->assertSame([], $context->getCustomerContext($customer));
        $this->assertTrue($context->getCustomerOrders($customer)->isEmpty());
        $this->assertTrue($context->getCustomerVouchers()->isEmpty());
        $this->assertSame([], $context->getAccountInfo($customer));
        $this->assertSame('', $context->formatContextForPrompt($customer));
        $this->assertSame([], $context->getPersonalizedPrice($customer, $customer));
    }

    /**
     * A host binding must win over the default.
     *
     * `bindIf`, not `bind`. Adding a default must not take the feature away
     * from an application that already binds its own implementation — the
     * inversion `AiChatServiceProvider`'s docblock describes stays intact.
     */
    #[Test]
    public function a_host_binding_overrides_the_default(): void
    {
        $hostValidator = new class implements ChatProductCardContract
        {
            public function validateCards(array $products): array
            {
                return $products;
            }
        };

        $this->app->bind(ChatProductCardContract::class, fn (): ChatProductCardContract => $hostValidator);

        $this->assertSame($hostValidator, app(ChatProductCardContract::class));
        $this->assertNotInstanceOf(NullChatProductCardValidator::class, app(ChatProductCardContract::class));
    }
}
