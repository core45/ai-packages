<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

/**
 * Marks a default implementation as "this application has not configured this
 * seam", so that its empty answers are never mistaken for findings.
 *
 * The packages ship null-object defaults so a fresh `composer require` boots
 * instead of throwing `BindingResolutionException`. For most seams that is
 * simply the feature being off, and an empty answer is the truth.
 *
 * For a few it is not, and the difference is destructive. `ResolvesUrlToModel`
 * returns `null` for "this URL does not point at a model I know", and
 * `LinkVerificationService` reads that as "broken link" and REMOVES it from the
 * post body. A resolver that cannot resolve anything returns `null` for every
 * URL — so without this marker, installing the packages and wiring only the
 * link *scanner* (pure HTML parsing, easy) but not the *resolver* (needs host
 * routing, harder) would strip every internal link from every published post it
 * processed, and log each one as "Broken internal link removed". They were not
 * broken. Nothing could vouch for them.
 *
 * So: an unconfigured seam's answer is an absence of information, not
 * information. Code that DESTROYS on a negative result must check for this
 * marker and do nothing instead. Code that merely skips work does not need to
 * care — skipping is already the right response to a feature being off.
 *
 * Implement this only on a default that ships INSIDE a package. A host binding
 * its own implementation is a configured seam by definition, however little
 * that implementation does.
 */
interface UnconfiguredSeam {}
