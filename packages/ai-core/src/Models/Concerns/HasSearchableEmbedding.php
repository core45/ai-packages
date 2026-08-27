<?php

declare(strict_types=1);

namespace Core45\AiCore\Models\Concerns;

/**
 * Adds an `_vectors` entry to a Scout searchable array for Meilisearch
 * semantic search.
 *
 * NOTE: the embedding generator (a host-namespaced embedding service in the
 * reference implementation) is not part of this host application yet, so
 * this is a no-op stub: it returns the searchable array unchanged. Once an
 * embedding service is added, wire it in here.
 */
trait HasSearchableEmbedding
{
    abstract public function getEmbeddingText(): string;

    /**
     * @param  array<string, mixed>  $searchableArray
     * @return array<string, mixed>
     */
    protected function appendSearchableEmbedding(array $searchableArray): array
    {
        return $searchableArray;
    }
}
