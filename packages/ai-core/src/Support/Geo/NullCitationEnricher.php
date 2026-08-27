<?php

declare(strict_types=1);

namespace Core45\AiCore\Support\Geo;

use Core45\AiCore\Contracts\EnrichesCitations;
use Core45\AiCore\Support\Seo\NullFocusKeyphraseStore;
use Illuminate\Database\Eloquent\Model;

/**
 * The default when the host has not bound its own GEO citation enrichment.
 *
 * Like `StoresFocusKeyphrases`, this interface leads with a capability probe,
 * so "this application enriches nothing" is already expressible: `supports()`
 * returns false and the pipeline skips the phase.
 *
 * Two things made the missing binding worse than it looks. The rewrite pipeline
 * resolved it **before** testing its own feature flags —
 *
 *     $citationEnricher = app(EnrichesCitations::class);
 *     if (config('geo.citation.enabled') && ...)
 *
 * — so turning the feature off could not prevent the failure. And
 * `geo.citation.enabled` is not a key `ai-core/config/geo.php` ships, so it is
 * null in a fresh install and the phase would have been skipped anyway. The
 * install broke on a feature it was never going to run.
 *
 * A host with a GEO service binds its own implementation; `bindIf` leaves it
 * alone.
 */
final class NullCitationEnricher implements EnrichesCitations
{
    public function supports(Model $model): bool
    {
        return false;
    }

    /**
     * Unreachable through the pipeline, which checks `supports()` first.
     *
     * A no-op rather than a throw, for the same reason as
     * {@see NullFocusKeyphraseStore::save()}: a
     * default binding that throws converts an absent optional feature into a
     * broken install.
     */
    public function enrich(Model $model, string $locale): void
    {
        // Nothing enriches citations in this application.
    }
}
