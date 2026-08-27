<?php

declare(strict_types=1);

namespace Core45\AiChat\Contracts;

/**
 * D8 seam: validates and renders assistant product recommendations as card
 * data for the customer chat panel. The package defines the shape; the host
 * (or later ai-products) binds the implementation that queries its own
 * product model — AI-provided values are NEVER trusted for critical fields,
 * which remains the implementation's responsibility.
 */
interface ChatProductCardContract
{
    /**
     * Validate assistant-suggested products against real records and merge
     * authoritative field values.
     *
     * @param  array<int, array<string, mixed>>  $products
     * @return array<int, array<string, mixed>>
     */
    public function validateCards(array $products): array;
}
