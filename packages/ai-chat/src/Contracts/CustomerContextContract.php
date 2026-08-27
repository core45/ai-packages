<?php

declare(strict_types=1);

namespace Core45\AiChat\Contracts;

use Illuminate\Support\Collection;

/**
 * D9 seam: supplies customer order history, voucher state, account info and
 * personalized pricing context as PLAIN arrays/scalars to the chat transport.
 *
 * The package never imports host domain models: implementations (bound by the
 * host, later possibly ai-products) accept the customer as a generic object
 * and assert their own model types internally. Everything crossing this seam
 * is primitive-shaped so the contract stays portable.
 */
interface CustomerContextContract
{
    /**
     * @param  object  $customer  host user/customer model
     * @return array<string, mixed>
     */
    public function getCustomerContext(object $customer): array;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getCustomerOrders(object $customer): Collection;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function getCustomerVouchers(): Collection;

    /**
     * @return array<string, mixed>
     */
    public function getAccountInfo(object $customer): array;

    public function formatContextForPrompt(object $customer): string;

    /**
     * @param  object  $product  host product model
     * @param  object|null  $customer  host user/customer model
     * @return array<string, mixed>
     */
    public function getPersonalizedPrice(object $product, ?object $customer): array;
}
