<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Contracts\ChecksContentSimilarity;
use Core45\AiCore\DTOs\SimilarityResult;
use Core45\AiCore\Models\ContentSimilarityCheck;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Service for checking content similarity.
 *
 * Uses Jaccard similarity with word shingles (n-grams) to detect
 * duplicate or near-duplicate content between items of the same model type.
 *
 * NOTE: the source implementation also supported comparison against a
 * product category relationship, external URL sources, and a semantic
 * (vector) similarity pass. None of a host `ContentSimilaritySource` model,
 * a host `SemanticSimilarityService`, or a `ProductCategory` relationship
 * exist in this package, so those code paths were stripped. Only
 * same-type internal comparison remains.
 *
 * Implements ai-core's `ChecksContentSimilarity` seam so `AiCoreServiceProvider`
 * can bind the contract to this concrete service.
 */
class ContentSimilarityService implements ChecksContentSimilarity
{
    private const DEFAULT_SHINGLE_SIZE = 5;

    private const MAX_COMPARISONS_PER_CHECK = 100;

    /**
     * Check similarity for a model's content field against all other
     * items of the same model type (Jaccard similarity).
     *
     * @param  array<string, mixed>  $options
     */
    public function checkSimilarity(
        Model $model,
        string $field,
        string $locale,
        array $options = []
    ): SimilarityResult {
        $content = $this->getModelContent($model, $field, $locale);

        if (empty($content)) {
            return SimilarityResult::fromComparisons([]);
        }

        $comparisons = [];

        if ($options['check_all'] ?? true) {
            $comparisons = array_merge($comparisons, $this->compareWithAll($model, $field, $locale, $content));
        }

        return SimilarityResult::fromComparisons($comparisons);
    }

    /**
     * Calculate similarity between two pieces of content.
     *
     * Uses Jaccard similarity coefficient with word shingles.
     *
     * `$shingleSize` is nullable to match `ChecksContentSimilarity`, which
     * deliberately lets each implementation keep its own default rather than
     * making callers pick an n-gram width. This class predates that contract
     * and declared `int $shingleSize = self::DEFAULT_SHINGLE_SIZE`, which is not
     * a compatible override: PHP raises a fatal when the class is loaded, so it
     * would have taken down every request that touched it rather than failing
     * quietly somewhere testable.
     */
    public function calculateSimilarity(string $first, string $second, ?int $shingleSize = null): float
    {
        $shingleSize ??= self::DEFAULT_SHINGLE_SIZE;

        if (empty($first) || empty($second)) {
            return 0.0;
        }

        $shingles1 = $this->generateShingles($first, $shingleSize);
        $shingles2 = $this->generateShingles($second, $shingleSize);

        if (empty($shingles1) || empty($shingles2)) {
            return 0.0;
        }

        $intersection = count(array_intersect($shingles1, $shingles2));
        $union = count(array_unique(array_merge($shingles1, $shingles2)));

        // Union is always > 0 here since we checked for empty arrays above
        return $intersection / $union;
    }

    /**
     * Get status for a similarity score.
     */
    public function getStatus(float $score): string
    {
        return SimilarityResult::getStatusForScore($score);
    }

    /**
     * Store similarity check result in database.
     */
    public function storeResult(
        Model $model,
        string $field,
        string $locale,
        SimilarityResult $result
    ): void {
        // Store only the highest similarity comparison
        if ($result->highestScore > 0) {
            ContentSimilarityCheck::updateOrCreate(
                [
                    'checkable_type' => get_class($model),
                    'checkable_id' => $model->getKey(),
                    'field' => $field,
                    'locale' => $locale,
                ],
                [
                    'compared_with_type' => $result->mostSimilarType,
                    'compared_with_reference' => $result->mostSimilarTo,
                    'similarity_score' => $result->highestScore,
                    'status' => $result->status,
                    'details' => $result->toArray(),
                ]
            );
        }
    }

    /**
     * Get the latest similarity check for a model.
     */
    public function getLatestCheck(Model $model, string $field, string $locale): ?ContentSimilarityCheck
    {
        return ContentSimilarityCheck::where('checkable_type', get_class($model))
            ->where('checkable_id', $model->getKey())
            ->where('field', $field)
            ->where('locale', $locale)
            ->latest()
            ->first();
    }

    /**
     * Get all checks for a model.
     *
     * @return Collection<int, ContentSimilarityCheck>
     */
    public function getChecksForModel(Model $model): Collection
    {
        return ContentSimilarityCheck::where('checkable_type', get_class($model))
            ->where('checkable_id', $model->getKey())
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Generate word shingles (n-grams) from text.
     *
     * @return array<int, string>
     */
    public function generateShingles(string $text, int $size = self::DEFAULT_SHINGLE_SIZE): array
    {
        // Normalize text
        $text = $this->normalizeText($text);

        // Split into words
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        if ($words === false || count($words) < $size) {
            return [];
        }

        $shingles = [];
        $wordCount = count($words);

        for ($i = 0; $i <= $wordCount - $size; $i++) {
            $shingles[] = implode(' ', array_slice($words, $i, $size));
        }

        return array_unique($shingles);
    }

    /**
     * Compare content with all other items of the same type.
     *
     * @return array<int, array{reference: string, type: string, score: float}>
     */
    private function compareWithAll(Model $model, string $field, string $locale, string $content): array
    {
        $comparisons = [];
        $modelClass = get_class($model);

        /** @var Builder<Model> $query */
        $query = $modelClass::query()
            ->where('id', '!=', $model->getKey())
            ->limit(self::MAX_COMPARISONS_PER_CHECK);

        foreach ($query->get() as $otherModel) {
            $otherContent = $this->getModelContent($otherModel, $field, $locale);
            if (empty($otherContent)) {
                continue;
            }

            $score = $this->calculateSimilarity($content, $otherContent);
            if ($score > 0) {
                $comparisons[] = [
                    'reference' => (string) $otherModel->getKey(),
                    'type' => ContentSimilarityCheck::TYPE_INTERNAL,
                    'score' => $score,
                ];
            }
        }

        return $comparisons;
    }

    /**
     * Get content from a model's field.
     */
    private function getModelContent(Model $model, string $field, string $locale): string
    {
        // For translatable models
        if (method_exists($model, 'translate')) {
            $translation = $model->translate($locale);
            if ($translation && isset($translation->{$field})) {
                return strip_tags((string) $translation->{$field});
            }
        }

        // For non-translatable models
        if (isset($model->{$field})) {
            return strip_tags((string) $model->{$field});
        }

        return '';
    }

    /**
     * Normalize text for comparison.
     */
    private function normalizeText(string $text): string
    {
        // Remove HTML tags
        $text = strip_tags($text);

        // Convert to lowercase
        $text = mb_strtolower($text);

        // Remove punctuation
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text) ?? $text;

        // Normalize whitespace
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
