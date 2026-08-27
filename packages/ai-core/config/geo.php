<?php

declare(strict_types=1);

/**
 * D6 package-owned defaults for the GEO scorer.
 *
 * `AiCoreServiceProvider` merges this under the `geo` key. `mergeConfigFrom()`
 * merges RECURSIVELY with the consuming app's values on top, so an app that
 * already ships its own `config/geo.php` keeps every key it defines — including
 * the `models` list, which is host classes and deliberately has no default here.
 *
 * These four keys are not cosmetic. `GeoScorerService` reads them without a
 * fallback, exactly as the source application did: an unset `geo.site_name`
 * reaches `mb_strtolower(null)` (a deprecation, and this repo's phpunit.xml sets
 * `failOnWarning="true"`), and an unset `geo.scoring.min_description_words`
 * makes `$wordCount >= null` compare against 0 — silently passing the
 * `description_length` signal for every model forever. Shipping the defaults is
 * what stops a fresh install from scoring differently to an existing one.
 */
return [
    // Checked against the model's name + content by the `entity_consistency`
    // signal. Domain-neutral default: the app's own name, which is the closest
    // thing to a brand a package can know about.
    'site_name' => env('GEO_SITE_NAME', env('APP_NAME', 'Laravel')),

    'scoring' => [
        'min_description_words' => 150,
        'min_reviews_for_signal' => 10,
        'min_rating_for_signal' => 4.0,
    ],
];
