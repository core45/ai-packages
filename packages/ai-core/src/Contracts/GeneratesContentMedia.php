<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * D2 rewrite-pipeline seam: media generation for a rewritten record.
 *
 * The implementation needs the consuming app's media library configuration and
 * its own model conventions, so it stays consumer-owned — the same inversion as
 * {@see EnrichesCitations}, {@see GeneratesSlugs} and {@see StoresFocusKeyphrases}.
 *
 * Before this contract existed the rewrite services called
 * `app(AIMediaGenerationService::class)` with no import, which resolved to a
 * non-existent class inside the package namespace and threw at runtime.
 */
interface GeneratesContentMedia
{
    /**
     * Generate and attach media for the given record.
     *
     * @param  array<string, mixed>  $options  rich_text_field, gallery_collection, …
     */
    public function processMediaGeneration(Model $model, array $options): void;
}
