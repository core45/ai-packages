<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Focus-keyphrase persistence seam: the pipeline detects capability on the
 * model and persists the keyphrase. The consumer owns the SEO indexable
 * storage (trait detection + table writes stay host-side).
 */
interface StoresFocusKeyphrases
{
    /** Whether this model is capable of holding focus keyphrases. */
    public function supports(Model $model): bool;

    public function save(Model $model, string $locale, string $keyphrase): void;
}
