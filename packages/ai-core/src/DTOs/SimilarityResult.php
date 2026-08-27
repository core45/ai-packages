<?php

declare(strict_types=1);

namespace Core45\AiCore\DTOs;

use Core45\AiCore\Models\ContentSimilarityCheck;

/**
 * Data Transfer Object representing the result of a similarity check.
 *
 * Contains all comparison results and provides helper methods for
 * determining the overall status and recommended actions.
 */
final readonly class SimilarityResult
{
    public const THRESHOLD_FAIL = 0.75;

    public const THRESHOLD_WARNING = 0.60;

    /**
     * @param  array<int, array{reference: string, type: string, score: float, status: string}>  $comparisons
     */
    public function __construct(
        public string $status,
        public float $highestScore,
        public string $mostSimilarTo,
        public string $mostSimilarType,
        public array $comparisons,
    ) {}

    /**
     * Create from an array of comparison results.
     *
     * @param  array<int, array{reference: string, type: string, score: float}>  $comparisons
     */
    public static function fromComparisons(array $comparisons): self
    {
        if (empty($comparisons)) {
            return new self(
                status: ContentSimilarityCheck::STATUS_PASS,
                highestScore: 0.0,
                mostSimilarTo: '',
                mostSimilarType: '',
                comparisons: [],
            );
        }

        // Add status to each comparison and find highest
        $processed = [];
        $highestScore = 0.0;
        $mostSimilarTo = '';
        $mostSimilarType = '';

        foreach ($comparisons as $comparison) {
            $score = $comparison['score'];
            $status = self::getStatusForScore($score);

            $processed[] = [
                'reference' => $comparison['reference'],
                'type' => $comparison['type'],
                'score' => $score,
                'status' => $status,
            ];

            if ($score > $highestScore) {
                $highestScore = $score;
                $mostSimilarTo = $comparison['reference'];
                $mostSimilarType = $comparison['type'];
            }
        }

        return new self(
            status: self::getStatusForScore($highestScore),
            highestScore: $highestScore,
            mostSimilarTo: $mostSimilarTo,
            mostSimilarType: $mostSimilarType,
            comparisons: $processed,
        );
    }

    /**
     * Get the status for a given similarity score.
     */
    public static function getStatusForScore(float $score): string
    {
        if ($score >= self::THRESHOLD_FAIL) {
            return ContentSimilarityCheck::STATUS_FAIL;
        }

        if ($score >= self::THRESHOLD_WARNING) {
            return ContentSimilarityCheck::STATUS_WARNING;
        }

        return ContentSimilarityCheck::STATUS_PASS;
    }

    /**
     * Check if content should be regenerated.
     */
    public function shouldRegenerate(): bool
    {
        return $this->status === ContentSimilarityCheck::STATUS_FAIL;
    }

    /**
     * Check if content should be enhanced with additional sections.
     */
    public function shouldEnhance(): bool
    {
        return $this->status === ContentSimilarityCheck::STATUS_WARNING;
    }

    /**
     * Check if the content passed similarity checks.
     */
    public function passed(): bool
    {
        return $this->status === ContentSimilarityCheck::STATUS_PASS;
    }

    /**
     * Get the highest similarity score as a percentage.
     */
    public function getHighestScorePercentage(): float
    {
        return round($this->highestScore * 100, 1);
    }

    /**
     * Get the number of failed comparisons.
     */
    public function getFailedCount(): int
    {
        return count(array_filter(
            $this->comparisons,
            fn (array $c): bool => $c['status'] === ContentSimilarityCheck::STATUS_FAIL
        ));
    }

    /**
     * Get the number of warning comparisons.
     */
    public function getWarningCount(): int
    {
        return count(array_filter(
            $this->comparisons,
            fn (array $c): bool => $c['status'] === ContentSimilarityCheck::STATUS_WARNING
        ));
    }

    /**
     * Get a summary description of the result.
     */
    public function getSummary(): string
    {
        if ($this->passed()) {
            return 'Content passed similarity checks.';
        }

        $percentage = $this->getHighestScorePercentage();

        if ($this->shouldRegenerate()) {
            return "Content is {$percentage}% similar to {$this->mostSimilarTo}. Regeneration recommended.";
        }

        return "Content is {$percentage}% similar to {$this->mostSimilarTo}. Consider adding unique sections.";
    }

    /**
     * Convert to array for storage in database.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'highest_score' => $this->highestScore,
            'most_similar_to' => $this->mostSimilarTo,
            'most_similar_type' => $this->mostSimilarType,
            'comparisons' => $this->comparisons,
            'failed_count' => $this->getFailedCount(),
            'warning_count' => $this->getWarningCount(),
        ];
    }
}
