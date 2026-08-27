<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A content model that can supply a per-locale SEO record.
 *
 * Why this exists (§6.7, §7): `AIContentable` declares four methods and package
 * code calls none of them. What it actually calls on a content model is
 * `translate()`, `translateOrNew()` and `getOrCreateSeoIndexable()`. The first
 * two are guaranteed by Astrotomic's `Translatable` contract, which the packages
 * now declare in composer.json and guard with `instanceof`. The third was
 * guaranteed by nothing — a duck-typed call that satisfies the linter, passes
 * every gate, and fatals at runtime on the first host model that lacks it.
 *
 * Registering a model under `config('ai.models')` resolves its CLASS. It says
 * nothing about which METHODS that class has. Those are different problems and
 * the registry key only solves the first, which is why this interface exists
 * rather than a wider intersection type or a duck-typed call.
 *
 * The return type is `Model`, not the host's own SEO model, because a package
 * contract must not name a host class. Implementors may narrow it — PHP allows
 * covariant return types, so the host trait returning its concrete
 * `SeoIndexable` satisfies this signature.
 *
 * Kept separate from `AIContentable` deliberately: SEO indexing is a host
 * concern, and widening `AIContentable` would force it onto every model that
 * implements that contract for unrelated reasons.
 */
interface ProvidesSeoIndexable
{
    /**
     * Get, or create on first use, the SEO record for this model in $locale.
     *
     * A null locale means the application's current locale.
     */
    public function getOrCreateSeoIndexable(?string $locale = null): Model;
}
