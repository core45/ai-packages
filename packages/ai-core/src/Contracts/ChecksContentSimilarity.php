<?php

declare(strict_types=1);

namespace Core45\AiCore\Contracts;

use Core45\AiCore\DTOs\SimilarityResult;
use Illuminate\Database\Eloquent\Model;

/**
 * Similarity-checking seam used by the rewrite pipeline's duplicate guard.
 * The consumer supplies its implementation (which knows its own content
 * tables and thresholds); the core only orchestrates.
 */
interface ChecksContentSimilarity
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function checkSimilarity(Model $model, string $field, string $locale, array $options = []): SimilarityResult;

    public function storeResult(Model $model, string $field, string $locale, SimilarityResult $result): void;

    /**
     * Jaccard similarity of two strings, 0.0 to 1.0.
     *
     * Unlike the two methods above this touches no model and no table — it is
     * pure text comparison. It is on the contract because ai-posts' pipeline
     * quality gate compares one module's output against another's, which never
     * involves a persisted record, and reaching for the concrete host service
     * to do that was the package's last similarity coupling.
     *
     * $shingleSize is the n-gram width; implementations should keep their own
     * default rather than have callers pick one.
     */
    public function calculateSimilarity(string $first, string $second, ?int $shingleSize = null): float;
}
