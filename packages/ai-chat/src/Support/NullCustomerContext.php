<?php

declare(strict_types=1);

namespace Core45\AiChat\Support;

use Core45\AiChat\Contracts\CustomerContextContract;
use Illuminate\Support\Collection;

/**
 * The default when the host has not bound its own customer-context provider.
 *
 * `CustomerAIService`'s constructor takes `CustomerContextContract`, and
 * `CustomerAIChatPanel::mount()` — Livewire's ordinary lifecycle hook, run
 * every time the chat widget renders — calls
 * `app(CustomerAIService::class)` unconditionally to build the welcome
 * message. Resolving that service resolves this contract before
 * `CustomerAIService::isEnabled()` is even checked, so a fresh install threw
 * `BindingResolutionException` the moment the chat panel was mounted, not
 * when a customer actually asked something personal.
 *
 * `CustomerContextContract`'s own docblock explains why there is no generic
 * default that fabricates orders or pricing: "the package never imports host
 * domain models". This package has no host user/order/voucher model to read
 * — that is D9, the entire reason the seam exists — so the only safe default
 * is "this customer has no context", the same direction
 * `NullFocusKeyphraseStore` and friends take in `ai-core`: an absent
 * optional feature degrades to an empty answer, not a broken install. The
 * chat still works; it just carries no personalization until the host binds
 * its own implementation.
 *
 * A host (or `ai-products`) binds its own implementation that reads real
 * orders, vouchers and pricing; `bindIf` leaves it alone.
 */
final class NullCustomerContext implements CustomerContextContract
{
    public function getCustomerContext(object $customer): array
    {
        return [];
    }

    /**
     * @return Collection<int, never>
     */
    public function getCustomerOrders(object $customer): Collection
    {
        return collect();
    }

    /**
     * @return Collection<int, never>
     */
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
}
