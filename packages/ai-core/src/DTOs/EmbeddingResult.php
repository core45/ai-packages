<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

/**
 * Result DTO for `AiClient::embed()`/`embedBatch()`.
 *
 * IDs + plain data only — never returns an Eloquent model instance.
 */
final readonly class EmbeddingResult
{
    /**
     * @param  array<int, float>|null  $vectors
     */
    public function __construct(
        public ?array $vectors,
        public ?int $dimensions,
        public ?int $count = null,
    ) {}

    /**
     * Parse phasea's embeddings envelope
     * `{"data": {"count": N, "dimensions": N, "vectors": [[float, ...], ...]}}`
     * (docs/ai-contract.md §6). `data.vectors` is an array of float arrays,
     * one per input text; `embed()` sends a single text, so the first element
     * is this result's vector. The `vectors` response field was added in
     * Plan 13-02 for phaseb's Meilisearch integration.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $envelope = $data['data'] ?? null;
        $envelope = is_array($envelope) ? $envelope : [];

        $vectorList = $envelope['vectors'] ?? null;
        $firstVector = is_array($vectorList) ? ($vectorList[0] ?? null) : null;
        $dimensions = $envelope['dimensions'] ?? null;
        $count = $envelope['count'] ?? null;

        return new self(
            vectors: self::normalizeVectors($firstVector),
            dimensions: is_int($dimensions) ? $dimensions : null,
            count: is_int($count) ? $count : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'vectors' => $this->vectors,
            'dimensions' => $this->dimensions,
            'count' => $this->count,
        ];
    }

    /**
     * A vector with ANY non-numeric component is rejected wholesale (returns
     * `null`, the documented failure value) — coercing corrupt components to
     * `0.0` would silently poison Meilisearch relevance (13-REVIEW IN-05).
     *
     * @return array<int, float>|null
     */
    private static function normalizeVectors(mixed $vectors): ?array
    {
        if (!is_array($vectors)) {
            return null;
        }

        $normalized = [];

        foreach ($vectors as $vector) {
            if (!is_numeric($vector)) {
                return null;
            }

            $normalized[] = (float) $vector;
        }

        return $normalized;
    }
}
