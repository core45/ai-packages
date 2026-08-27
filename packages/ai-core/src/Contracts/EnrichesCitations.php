<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * GEO citation enrichment seam: the rewrite pipeline asks whether a model is
 * enrichable and delegates the enrichment. The consumer binds its own
 * implementation (host CitationEnrichmentService today) — the core never
 * imports GEO/domain services.
 */
interface EnrichesCitations
{
    public function supports(Model $model): bool;

    public function enrich(Model $model, string $locale): void;
}
