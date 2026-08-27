<?php

declare(strict_types=1);

namespace Core45\AiCore\Support\Slugs;

use Core45\AiCore\Contracts\GeneratesSlugs;
use Illuminate\Support\Str;

/**
 * The default when the host has not bound its own slug generator.
 *
 * Unlike `StoresFocusKeyphrases` or `EnrichesCitations`, this is a REAL
 * implementation, not a null object: `GeneratesSlugs::make()` has no
 * capability probe to answer "no" with, and `Str::slug()` is the obviously
 * correct default — no host should have to write this themselves.
 *
 * Uniqueness is deliberately NOT this class's job. The sole call site,
 * `AbstractContentRewriteService::generateUniqueSlug()`, calls `make()` for
 * the base slug and then appends `-1`, `-2`, … itself while
 * `slugExistsForLocale()` says the candidate collides. `make()` only needs to
 * turn a title into a slug once; the retry loop belongs to the caller, which
 * already owns the locale-scoped existence check this class cannot see.
 *
 * `AiCoreServiceProvider` binds this with `bindIf`: a host with its own
 * `SlugGenerator` keeps it untouched.
 */
final class StrSlugGenerator implements GeneratesSlugs
{
    public function make(string $title): string
    {
        return Str::slug($title);
    }
}
