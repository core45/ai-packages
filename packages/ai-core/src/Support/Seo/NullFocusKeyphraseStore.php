<?php

declare(strict_types=1);

namespace Core45\AiCore\Support\Seo;

use Core45\AiCore\Contracts\StoresFocusKeyphrases;
use Illuminate\Database\Eloquent\Model;

/**
 * The default when the host has not bound its own focus-keyphrase storage.
 *
 * `StoresFocusKeyphrases` is a *capability probe plus a writer*: the pipeline
 * asks `supports()` first and only calls `save()` when the answer is yes. So
 * "the host stores focus keyphrases nowhere" has an exact expression in the
 * interface already — `supports()` returns false — and this class is simply
 * that answer, made available by default.
 *
 * Without it, `AbstractContentRewriteService::processFocusKeyphrases()` opens
 * with an unguarded `app(StoresFocusKeyphrases::class)`, so a fresh install
 * threw `BindingResolutionException` on **every** rewrite before reaching any
 * feature check. Exactly the failure the `GeneratesImages` binding in
 * `AiCoreServiceProvider` was added to fix — "a container error waiting for the
 * first person to open it" — in a second place that was missed.
 *
 * A host that wants keyphrases persisted binds its own implementation; `bindIf`
 * leaves it alone.
 */
final class NullFocusKeyphraseStore implements StoresFocusKeyphrases
{
    public function supports(Model $model): bool
    {
        return false;
    }

    /**
     * Unreachable through the pipeline, which checks `supports()` first.
     *
     * Deliberately a no-op rather than a thrown exception: a host that calls
     * this directly has made a mistake worth surfacing, but throwing from the
     * default binding would turn a missing optional feature into a broken
     * install — which is the thing this class exists to prevent.
     */
    public function save(Model $model, string $locale, string $keyphrase): void
    {
        // Nothing stores keyphrases in this application.
    }
}
