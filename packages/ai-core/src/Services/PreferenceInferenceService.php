<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Jobs\ComputeUserPreferencesJob;
use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Models\AIConversationMessage;
use Core45\AiCore\Models\AILearnedKnowledge;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;

/**
 * Service for inferring user preferences from conversation history.
 *
 * Analyzes:
 * - Language preferences (based on conversation language)
 * - Topic preferences (what users frequently ask about)
 * - Response style preferences (detailed vs concise)
 */
class PreferenceInferenceService
{
    /**
     * Language detection word sets - common words unique to each language.
     *
     * @var array<string, array<int, string>>
     */
    protected array $languageMarkers = [
        'en' => ['the', 'and', 'is', 'are', 'have', 'has', 'what', 'how', 'when', 'where', 'why', 'can', 'could', 'would', 'should'],
        'pl' => ['jest', 'nie', 'tak', 'czy', 'jak', 'się', 'na', 'do', 'ale', 'że', 'co', 'który', 'mój', 'ten', 'być'],
        'de' => ['und', 'nicht', 'mit', 'ist', 'sind', 'haben', 'kann', 'wie', 'was', 'warum', 'wann', 'wenn', 'aber', 'oder', 'für'],
        'es' => ['que', 'es', 'son', 'para', 'por', 'como', 'pero', 'más', 'este', 'cuando', 'donde', 'puede', 'tiene', 'hay', 'todo'],
        'fr' => ['que', 'est', 'sont', 'pour', 'avec', 'pas', 'mais', 'plus', 'quand', 'comment', 'pourquoi', 'peut', 'cette', 'tout', 'avoir'],
        'it' => ['che', 'non', 'con', 'per', 'sono', 'come', 'cosa', 'quando', 'dove', 'perché', 'può', 'questo', 'tutto', 'anche', 'essere'],
    ];

    /**
     * Full language names for display.
     *
     * @var array<string, string>
     */
    protected array $languageNames = [
        'en' => 'English',
        'pl' => 'Polish',
        'de' => 'German',
        'es' => 'Spanish',
        'fr' => 'French',
        'it' => 'Italian',
    ];

    /**
     * Analyze user's conversations to infer preferences.
     *
     * @return Collection<int, array{
     *     type: string,
     *     value: string,
     *     confidence: float,
     *     content: string
     * }>
     */
    public function inferPreferences(User $user): Collection
    {
        $conversations = AIConversation::query()
            ->where('user_id', $user->id)
            ->with('messages')
            ->latest()
            ->limit(50)
            ->get();

        if ($conversations->isEmpty()) {
            return collect();
        }

        $inferences = collect();

        // Analyze language preference
        $languagePreference = $this->inferLanguagePreference($conversations);
        if ($languagePreference) {
            $inferences->push($languagePreference);
        }

        // Analyze response style preference
        $stylePreference = $this->inferStylePreference($conversations);
        if ($stylePreference) {
            $inferences->push($stylePreference);
        }

        // Analyze common query topics
        $topicPreferences = $this->inferTopicPreferences($conversations);
        foreach ($topicPreferences as $topic) {
            $inferences->push($topic);
        }

        return $inferences;
    }

    /**
     * Infer the user's preferred language.
     *
     * @param  Collection<int, AIConversation>  $conversations
     * @return array{type: string, value: string, confidence: float, content: string}|null
     */
    protected function inferLanguagePreference(Collection $conversations): ?array
    {
        $languageCounts = array_fill_keys(array_keys($this->languageMarkers), 0);

        foreach ($conversations as $conversation) {
            /** @var AIConversationMessage $message */
            foreach ($conversation->messages as $message) {
                if ($message->role !== 'user') {
                    continue;
                }

                $lang = $this->detectLanguage($message->content);
                if (isset($languageCounts[$lang])) {
                    $languageCounts[$lang]++;
                }
            }
        }

        $total = array_sum($languageCounts);
        if ($total < 5 || $languageCounts === []) {
            return null; // Not enough data
        }

        $dominant = array_search(max($languageCounts), $languageCounts, true);
        if (!is_string($dominant)) {
            return null;
        }

        $dominantRatio = $languageCounts[$dominant] / $total;

        if ($dominantRatio > 0.7) {
            return [
                'type' => 'language',
                'value' => $dominant,
                'confidence' => round($dominantRatio, 2),
                'content' => "User prefers responses in {$this->languageNames[$dominant]}",
            ];
        }

        return null;
    }

    /**
     * Detect the language of a text using word markers.
     */
    protected function detectLanguage(string $text): string
    {
        $textLower = strtolower($text);
        $words = str_word_count($textLower, 1);
        $wordSet = array_flip($words);

        $scores = [];
        foreach ($this->languageMarkers as $lang => $markers) {
            $matches = 0;
            foreach ($markers as $marker) {
                if (isset($wordSet[$marker])) {
                    $matches++;
                }
            }
            $scores[$lang] = $matches;
        }

        // Default to English if no clear winner
        if ($scores === []) {
            return 'en';
        }

        $maxScore = max($scores);
        if ($maxScore < 2) {
            return 'en';
        }

        $bestLang = array_search($maxScore, $scores, true);

        return is_string($bestLang) ? $bestLang : 'en';
    }

    /**
     * Infer if user prefers detailed or concise responses.
     *
     * @param  Collection<int, AIConversation>  $conversations
     * @return array{type: string, value: string, confidence: float, content: string}|null
     */
    protected function inferStylePreference(Collection $conversations): ?array
    {
        $detailRequests = 0;
        $conciseRequests = 0;

        $detailPatterns = [
            '/more detail/i',
            '/explain/i',
            '/elaborate/i',
            '/tell me more/i',
            '/what do you mean/i',
            '/can you clarify/i',
        ];

        $concisePatterns = [
            '/short answer/i',
            '/briefly/i',
            '/in summary/i',
            '/tldr/i',
            '/quick/i',
            '/just tell me/i',
        ];

        foreach ($conversations as $conversation) {
            /** @var AIConversationMessage $message */
            foreach ($conversation->messages as $message) {
                if ($message->role !== 'user') {
                    continue;
                }

                foreach ($detailPatterns as $pattern) {
                    if (preg_match($pattern, $message->content)) {
                        $detailRequests++;
                        break;
                    }
                }

                foreach ($concisePatterns as $pattern) {
                    if (preg_match($pattern, $message->content)) {
                        $conciseRequests++;
                        break;
                    }
                }
            }
        }

        $total = $detailRequests + $conciseRequests;
        if ($total < 3) {
            return null; // Not enough data
        }

        if ($detailRequests > $conciseRequests * 2) {
            return [
                'type' => 'response_style',
                'value' => 'detailed',
                'confidence' => round($detailRequests / $total, 2),
                'content' => 'User prefers detailed, comprehensive responses',
            ];
        }

        if ($conciseRequests > $detailRequests * 2) {
            return [
                'type' => 'response_style',
                'value' => 'concise',
                'confidence' => round($conciseRequests / $total, 2),
                'content' => 'User prefers brief, to-the-point responses',
            ];
        }

        return null;
    }

    /**
     * Analyze common topics the user asks about.
     *
     * The topic taxonomy is host-supplied via config: ai-core is a
     * domain-neutral package and cannot know the consuming app's subject
     * matter (e-commerce, healthcare, etc.), so it is not hardcoded here.
     *
     * @param  Collection<int, AIConversation>  $conversations
     * @return array<int, array{type: string, value: string, confidence: float, content: string}>
     */
    protected function inferTopicPreferences(Collection $conversations): array
    {
        /** @var array<string, list<string>> $topicPatterns */
        $topicPatterns = config('ai.topic_patterns', []);

        $topicCounts = array_fill_keys(array_keys($topicPatterns), 0);
        $totalMessages = 0;

        foreach ($conversations as $conversation) {
            /** @var AIConversationMessage $message */
            foreach ($conversation->messages as $message) {
                if ($message->role !== 'user') {
                    continue;
                }

                $totalMessages++;

                foreach ($topicPatterns as $topic => $patterns) {
                    foreach ($patterns as $pattern) {
                        if (preg_match($pattern, $message->content)) {
                            $topicCounts[$topic]++;
                            break;
                        }
                    }
                }
            }
        }

        if ($totalMessages < 10) {
            return [];
        }

        $preferences = [];
        foreach ($topicCounts as $topic => $count) {
            $ratio = $count / $totalMessages;
            if ($ratio > 0.3) { // Topic appears in 30%+ of messages
                $preferences[] = [
                    'type' => 'topic_interest',
                    'value' => $topic,
                    'confidence' => round($ratio, 2),
                    'content' => "User frequently asks about {$topic}",
                ];
            }
        }

        return $preferences;
    }

    /**
     * Save an inferred preference as knowledge.
     *
     * @param  array{type: string, value: string, confidence: float, content: string}  $inference
     */
    public function saveInferredPreference(array $inference, User $user): AILearnedKnowledge
    {
        return app(KnowledgeService::class)->create([
            'type' => 'preference',
            'tier' => 'contextual',
            'audience' => 'customer',
            'title' => "User Preference: {$inference['type']}",
            'content' => $inference['content'],
            'source_type' => 'inferred',
            'enabled' => true,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Get all preferences for a user.
     *
     * @return Collection<int, array{type: string, value: string, confidence: float, content: string}>
     */
    public function getPreferencesForUser(User $user): Collection
    {
        return $this->inferPreferences($user);
    }

    /**
     * Get language preference for a user (if determinable).
     */
    public function getLanguagePreference(User $user): ?string
    {
        $conversations = AIConversation::query()
            ->where('user_id', $user->id)
            ->with('messages')
            ->latest()
            ->limit(20)
            ->get();

        $preference = $this->inferLanguagePreference($conversations);

        return $preference ? $preference['value'] : null;
    }

    /**
     * Get cached preferences for a user (fast, no DB queries).
     * Returns null if no cached preferences exist.
     *
     * @return array<int, array{type: string, value: string, confidence: float, content: string}>|null
     */
    public function getCachedPreferences(User $user): ?array
    {
        return ComputeUserPreferencesJob::getCachedPreferences($user->id);
    }

    /**
     * Get preferences formatted for system prompt injection.
     * Uses cached preferences for performance - returns empty string if no cache.
     *
     * @param  float  $minConfidence  Minimum confidence threshold (0-1)
     */
    public function getPreferencesForPrompt(User $user, float $minConfidence = 0.7): string
    {
        $cached = $this->getCachedPreferences($user);

        if (empty($cached)) {
            // Queue preference computation for next time
            ComputeUserPreferencesJob::dispatch($user->id);

            return '';
        }

        // Filter by confidence threshold
        $highConfidence = array_filter(
            $cached,
            fn (array $pref) => $pref['confidence'] >= $minConfidence
        );

        if (empty($highConfidence)) {
            return '';
        }

        $lines = ['USER PREFERENCES (inferred from history):'];

        foreach ($highConfidence as $pref) {
            $lines[] = "- {$pref['content']} (confidence: ".round($pref['confidence'] * 100).'%)';
        }

        return implode("\n", $lines);
    }

    /**
     * Schedule preference computation for a user.
     * Call this after a conversation ends to update cached preferences.
     */
    public function schedulePreferenceComputation(User $user): void
    {
        ComputeUserPreferencesJob::dispatch($user->id);
    }

    /**
     * Check if preferences are cached for a user.
     */
    public function hasCachedPreferences(User $user): bool
    {
        return ComputeUserPreferencesJob::hasCachedPreferences($user->id);
    }
}
