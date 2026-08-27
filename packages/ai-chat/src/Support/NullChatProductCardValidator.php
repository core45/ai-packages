<?php

declare(strict_types=1);

namespace Core45\AiChat\Support;

use Core45\AiChat\Contracts\ChatProductCardContract;

/**
 * The default when the host has not bound its own product-card validator.
 *
 * `CustomerAIChatPanel::validateProducts()` calls
 * `app(ChatProductCardContract::class)` unconditionally whenever the
 * assistant's response parses as a product recommendation — a normal part of
 * an ordinary customer conversation, not something the host opts into
 * separately. Nothing guards the call (the surrounding `try`/`catch` only
 * catches `JsonException` from the JSON parse a few lines above), so a fresh
 * install threw `BindingResolutionException` the first time the assistant
 * recommended a product.
 *
 * `ChatProductCardContract`'s own docblock is explicit about why there is no
 * generic default that trusts the AI's output: "AI-provided values are NEVER
 * trusted for critical fields". This package has no product model to
 * validate against — that is the entire reason the seam exists (D8) — so the
 * only safe default is to validate nothing and render no cards, the same
 * direction `NullFocusKeyphraseStore` and friends take in `ai-core`: an
 * absent optional feature degrades to "does nothing", never to "trusts
 * unvalidated input".
 *
 * A host (or `ai-products`) binds its own implementation that checks
 * suggested products against real records; `bindIf` leaves it alone.
 */
final class NullChatProductCardValidator implements ChatProductCardContract
{
    public function validateCards(array $products): array
    {
        return [];
    }
}
