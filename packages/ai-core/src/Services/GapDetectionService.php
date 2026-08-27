<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Models\AIKnowledgeGap;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for detecting and recording knowledge gaps.
 *
 * A knowledge gap is a question the AI couldn't answer confidently,
 * detected via confidence analysis of the AI response.
 */
class GapDetectionService
{
    /**
     * Confidence threshold below which a gap is detected.
     */
    protected float $confidenceThreshold;

    /**
     * Patterns that indicate low confidence in a response.
     *
     * @var array<string, float>
     */
    protected array $lowConfidencePatterns = [
        '/I\'?m not sure/i' => 0.15,
        '/I don\'?t have (?:that |specific )?information/i' => 0.20,
        '/I\'?m (?:not )?able to find/i' => 0.15,
        '/I don\'?t know/i' => 0.25,
        '/I can\'?t find/i' => 0.15,
        '/I couldn\'?t find/i' => 0.15,
        '/I don\'?t have access/i' => 0.20,
        '/(?:unfortunately|sadly),? I/i' => 0.10,
        '/I would recommend (?:contacting|checking with)/i' => 0.10,
        '/(?:please )?contact (?:us|support|customer service)/i' => 0.10,
        '/I\'?m unable to/i' => 0.15,
        '/that information (?:is )?not available/i' => 0.20,
        '/I don\'?t have (?:enough|sufficient) information/i' => 0.20,
        '/beyond (?:my|the) (?:scope|knowledge)/i' => 0.20,
    ];

    public function __construct()
    {
        $this->confidenceThreshold = (float) config(
            'ai.assistant.learning.gap_detection.confidence_threshold',
            0.4
        );
    }

    /**
     * Analyze an AI response and detect if there's a knowledge gap.
     */
    public function analyzeResponse(
        string $userQuestion,
        string $aiResponse,
        string $audience = 'public',
        ?AIConversation $conversation = null
    ): ?AIKnowledgeGap {
        if (!$this->isGapDetectionEnabled()) {
            return null;
        }

        $confidence = $this->calculateConfidence($aiResponse);

        if ($confidence >= $this->confidenceThreshold) {
            return null; // Confident response, no gap
        }

        // Check if a similar gap already exists
        $existingGap = $this->findSimilarGap($userQuestion, $audience);

        if ($existingGap) {
            // Increment existing gap
            $existingGap->incrementOccurrence($userQuestion);
            Log::channel('ai')->info('Knowledge gap occurrence incremented', [
                'gap_id' => $existingGap->id,
                'occurrence_count' => $existingGap->occurrence_count,
            ]);

            return $existingGap;
        }

        // Create new gap
        $gap = AIKnowledgeGap::create([
            'question' => $userQuestion,
            'ai_response' => $aiResponse,
            'confidence_score' => $confidence,
            'audience' => $audience,
            'conversation_id' => $conversation?->id,
            'occurrence_count' => 1,
            'last_occurred_at' => now(),
            'status' => 'open',
        ]);

        Log::channel('ai')->info('New knowledge gap detected', [
            'gap_id' => $gap->id,
            'question' => substr($userQuestion, 0, 100),
            'confidence' => $confidence,
        ]);

        return $gap;
    }

    /**
     * Calculate confidence score using penalty stacking.
     * Returns a value between 0 (no confidence) and 1 (full confidence).
     */
    public function calculateConfidence(string $response): float
    {
        // Start with full confidence
        $confidence = 1.0;

        // Apply penalties for low-confidence patterns
        foreach ($this->lowConfidencePatterns as $pattern => $penalty) {
            if (preg_match($pattern, $response)) {
                $confidence -= $penalty;
            }
        }

        // Apply penalty for very short responses (might indicate uncertainty)
        $wordCount = str_word_count($response);
        if ($wordCount < 10) {
            $confidence -= 0.15;
        }

        // Apply penalty for question marks in response (asking for clarification)
        $questionMarks = substr_count($response, '?');
        if ($questionMarks > 2) {
            $confidence -= 0.10;
        }

        // Ensure confidence stays in valid range
        return max(0.0, min(1.0, $confidence));
    }

    /**
     * Find a similar existing gap based on question text.
     */
    protected function findSimilarGap(string $question, string $audience): ?AIKnowledgeGap
    {
        // Extract keywords from the question
        $keywords = $this->extractKeywords($question);

        if (count($keywords) < 2) {
            return null;
        }

        // Search for gaps with similar keywords
        $gaps = AIKnowledgeGap::query()
            ->open()
            ->forAudience($audience)
            ->get();

        foreach ($gaps as $gap) {
            $gapKeywords = $this->extractKeywords($gap->question);
            $overlap = array_intersect($keywords, $gapKeywords);

            // Consider similar if at least 50% keyword overlap
            $overlapRatio = count($overlap) / max(count($keywords), 1);
            if ($overlapRatio >= 0.5) {
                return $gap;
            }
        }

        return null;
    }

    /**
     * Extract significant keywords from a question.
     *
     * @return array<int, string>
     */
    protected function extractKeywords(string $text): array
    {
        $stopWords = [
            'the', 'a', 'an', 'is', 'are', 'was', 'were', 'be', 'been',
            'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will',
            'would', 'could', 'should', 'may', 'might', 'must', 'shall',
            'can', 'need', 'to', 'of', 'in', 'for', 'on', 'with', 'at',
            'by', 'from', 'as', 'into', 'through', 'and', 'but', 'or',
            'so', 'yet', 'not', 'this', 'that', 'these', 'those', 'what',
            'which', 'who', 'whom', 'how', 'when', 'where', 'why', 'your',
            'my', 'our', 'their', 'its', 'i', 'you', 'we', 'they', 'it',
            'he', 'she', 'me', 'him', 'her', 'us', 'them',
        ];

        $words = str_word_count(strtolower($text), 1);
        $words = array_diff($words, $stopWords);
        $words = array_filter($words, fn ($w) => strlen($w) >= 3);

        return array_values(array_unique($words));
    }

    /**
     * Check if gap detection is enabled.
     */
    protected function isGapDetectionEnabled(): bool
    {
        return (bool) config('ai.assistant.learning.gap_detection.enabled', true);
    }

    /**
     * Get open gaps prioritized by occurrence count.
     *
     * @return Collection<int, AIKnowledgeGap>
     */
    public function getPrioritizedGaps(int $limit = 20): Collection
    {
        return AIKnowledgeGap::query()
            ->open()
            ->prioritized()
            ->limit($limit)
            ->get();
    }

    /**
     * Get gaps for a specific audience.
     *
     * @return Collection<int, AIKnowledgeGap>
     */
    public function getGapsForAudience(string $audience, int $limit = 20): Collection
    {
        return AIKnowledgeGap::query()
            ->open()
            ->forAudience($audience)
            ->prioritized()
            ->limit($limit)
            ->get();
    }

    /**
     * Get statistics about knowledge gaps.
     *
     * @return array<string, mixed>
     */
    public function getStats(): array
    {
        return [
            'total' => AIKnowledgeGap::count(),
            'open' => AIKnowledgeGap::open()->count(),
            'resolved' => AIKnowledgeGap::resolved()->count(),
            'ignored' => AIKnowledgeGap::ignored()->count(),
            'frequent' => AIKnowledgeGap::open()->frequent()->count(),
            'by_audience' => [
                'public' => AIKnowledgeGap::open()->forAudience('public')->count(),
                'customer' => AIKnowledgeGap::open()->forAudience('customer')->count(),
                'admin' => AIKnowledgeGap::open()->forAudience('admin')->count(),
            ],
        ];
    }
}
