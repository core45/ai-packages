<?php

declare(strict_types=1);

namespace Core45\AiCore\Services;

use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Models\AILearnedKnowledge;
use Core45\AiCore\Traits\LogsAIOperations;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Log;

/**
 * Service for detecting learning triggers in conversations.
 *
 * Detects:
 * - Explicit commands: "Remember that...", "Always remember...", etc.
 * - Corrections: "Actually...", "No, it's...", "That's wrong...", etc.
 * - Information patterns: Statements sharing business information
 */
class LearningDetectorService
{
    use LogsAIOperations;

    /**
     * Triggers for explicit learning commands.
     *
     * @var array<int, string>
     */
    protected array $explicitTriggers;

    /**
     * Patterns that indicate user is correcting the AI.
     *
     * @var array<int, string>
     */
    protected array $correctionPatterns;

    /**
     * Patterns that suggest the user is sharing store-specific information.
     *
     * @var array<int, string>
     */
    protected array $informationPatterns = [
        '/our\s+(store|shop|warehouse|office)\s+(is|opens?|closes?|hours?)/i',
        '/we\s+(offer|provide|have|ship|deliver|accept)/i',
        '/shipping\s+(takes?|is|costs?|time)/i',
        '/warranty\s+(is|lasts?|covers?|period)/i',
        '/returns?\s+(policy|within|accepted|period)/i',
        '/our\s+(phone|email|address|contact)/i',
        '/payment\s+(methods?|options?|accept)/i',
        '/prices?\s+(are|include|exclude)/i',
        '/discount\s+(code|coupon|offer)/i',
        '/delivery\s+(time|cost|free)/i',
    ];

    public function __construct()
    {
        $this->explicitTriggers = config('ai.assistant.learning.triggers.explicit_commands', [
            'remember that',
            'always remember',
            'never forget',
            'save this',
            'keep in mind',
        ]);

        $this->correctionPatterns = config('ai.assistant.learning.triggers.correction_patterns', [
            'actually',
            'no, it\'s',
            'that\'s wrong',
            'incorrect',
            'not quite',
        ]);
    }

    /**
     * Detect if a message contains an explicit learning command.
     *
     * @return array{type: string, trigger: string, content: string}|null
     */
    public function detectExplicitCommand(string $message): ?array
    {
        $this->logDebug('LearningDetector - Checking Explicit Commands', [
            'message_length' => strlen($message),
            'triggers_count' => count($this->explicitTriggers),
        ]);

        $messageLower = strtolower($message);

        foreach ($this->explicitTriggers as $trigger) {
            if (str_contains($messageLower, $trigger)) {
                $result = [
                    'type' => 'explicit',
                    'trigger' => $trigger,
                    'content' => $this->extractContentAfterTrigger($message, $trigger),
                ];

                $this->logDebug('LearningDetector - Explicit Command Detected', [
                    'trigger' => $trigger,
                    'extracted_content_length' => strlen($result['content']),
                ]);

                return $result;
            }
        }

        $this->logDebug('LearningDetector - No Explicit Command Found');

        return null;
    }

    /**
     * Detect if user is correcting the AI.
     *
     * @return array{type: string, trigger: string, correction: string, full_message: string, original_response: string}|null
     */
    public function detectCorrection(string $userMessage, string $previousAiResponse): ?array
    {
        $this->logDebug('LearningDetector - Checking Corrections', [
            'user_message_length' => strlen($userMessage),
            'ai_response_length' => strlen($previousAiResponse),
            'patterns_count' => count($this->correctionPatterns),
        ]);

        $messageLower = strtolower($userMessage);

        foreach ($this->correctionPatterns as $pattern) {
            if (str_contains($messageLower, $pattern)) {
                $correction = $this->extractCorrection($userMessage, $pattern);

                if ($correction) {
                    $result = [
                        'type' => 'correction',
                        'trigger' => $pattern,
                        'correction' => $correction,
                        'full_message' => $userMessage,
                        'original_response' => $previousAiResponse,
                    ];

                    $this->logDebug('LearningDetector - Correction Detected', [
                        'trigger' => $pattern,
                        'correction_length' => strlen($correction),
                    ]);

                    return $result;
                }
            }
        }

        $this->logDebug('LearningDetector - No Correction Found');

        return null;
    }

    /**
     * Analyze user message to detect if it's sharing business information.
     *
     * @return array{type: string, trigger: string, content: string, pattern: string}|null
     */
    public function detectSuggestedLearning(string $userMessage): ?array
    {
        $this->logDebug('LearningDetector - Checking Information Patterns', [
            'message_length' => strlen($userMessage),
            'patterns_count' => count($this->informationPatterns),
        ]);

        foreach ($this->informationPatterns as $pattern) {
            if (preg_match($pattern, $userMessage)) {
                $result = [
                    'type' => 'suggested',
                    'trigger' => 'information_pattern',
                    'content' => $userMessage,
                    'pattern' => $pattern,
                ];

                $this->logDebug('LearningDetector - Information Pattern Matched', [
                    'pattern' => $pattern,
                ]);

                return $result;
            }
        }

        $this->logDebug('LearningDetector - No Information Pattern Matched');

        return null;
    }

    /**
     * Create a learning suggestion to show to the user.
     *
     * @param  array<string, mixed>  $detection
     * @return array<string, mixed>
     */
    public function createLearningSuggestion(array $detection, AIConversation $conversation): array
    {
        $content = $this->cleanContent($detection['content']);
        $defaultTier = $this->determineDefaultTier($detection['type'], $content);

        $suggestion = [
            'type' => 'learning_suggestion',
            'message' => 'Should I remember this for future conversations?',
            'preview' => $content,
            'metadata' => [
                'detection_type' => $detection['type'],
                'suggested_tier' => $defaultTier,
                'suggested_audience' => 'public',
                'suggested_type' => $this->determineKnowledgeType($detection),
                'conversation_id' => $conversation->id,
            ],
        ];

        $this->logDebug('LearningDetector - Learning Suggestion Created', [
            'detection_type' => $detection['type'],
            'suggested_tier' => $defaultTier,
            'suggested_type' => $suggestion['metadata']['suggested_type'],
            'content_length' => strlen($content),
            'conversation_id' => $conversation->id,
        ]);

        return $suggestion;
    }

    /**
     * Save learning from user confirmation.
     */
    public function saveLearning(
        string $content,
        string $type,
        string $audience,
        ?int $conversationId,
        User $user
    ): AILearnedKnowledge {
        $this->logDebug('LearningDetector - Saving Learning', [
            'type' => $type,
            'audience' => $audience,
            'content_length' => strlen($content),
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
        ]);

        $title = $this->generateTitle($content);
        $tier = $this->determineDefaultTier($type, $content);

        $knowledge = app(KnowledgeService::class)->create([
            'title' => $title,
            'type' => $type,
            'audience' => $audience,
            'content' => $content,
            'source_type' => 'suggested',
            'tier' => $tier,
            'enabled' => true,
            'created_by' => $user->id,
        ]);

        $this->logDebug('LearningDetector - Learning Saved', [
            'knowledge_id' => $knowledge->id,
            'type' => $type,
            'tier' => $tier,
            'title' => $title,
        ]);

        Log::channel('ai')->info('Knowledge learned from conversation', [
            'knowledge_id' => $knowledge->id,
            'type' => $type,
            'conversation_id' => $conversationId,
        ]);

        return $knowledge;
    }

    /**
     * Save a correction as learned knowledge.
     *
     * @param  array<string, mixed>  $correctionData
     */
    public function saveCorrectionLearning(
        array $correctionData,
        ?int $conversationId,
        User $user,
        string $audience = 'public'
    ): AILearnedKnowledge {
        $this->logDebug('LearningDetector - Saving Correction Learning', [
            'trigger' => $correctionData['trigger'] ?? 'unknown',
            'correction_length' => strlen($correctionData['correction'] ?? ''),
            'user_id' => $user->id,
            'conversation_id' => $conversationId,
            'audience' => $audience,
        ]);

        $content = 'CORRECTION: '.$correctionData['correction'];
        $title = 'Correction: '.$this->generateTitle($correctionData['correction']);
        $tier = $this->determineDefaultTier('correction', $content);

        $knowledge = app(KnowledgeService::class)->create([
            'title' => $title,
            'type' => 'fact', // Corrections become facts
            'audience' => $audience,
            'content' => $content,
            'source_type' => 'suggested',
            'tier' => $tier,
            'enabled' => true,
            'created_by' => $user->id,
        ]);

        $this->logDebug('LearningDetector - Correction Saved', [
            'knowledge_id' => $knowledge->id,
            'tier' => $tier,
            'title' => $title,
        ]);

        Log::channel('ai')->info('Correction learned from conversation', [
            'knowledge_id' => $knowledge->id,
            'conversation_id' => $conversationId,
        ]);

        return $knowledge;
    }

    /**
     * Extract content after the trigger phrase.
     */
    protected function extractContentAfterTrigger(string $message, string $trigger): string
    {
        $pos = stripos($message, $trigger);
        if ($pos === false) {
            return $message;
        }

        $content = substr($message, $pos + strlen($trigger));

        return trim($content, " \t\n\r\0\x0B:.,");
    }

    /**
     * Extract the correct information from a correction message.
     */
    protected function extractCorrection(string $message, string $trigger): ?string
    {
        $patterns = [
            '/(?:actually|no),?\s*(?:it\'?s?|that\'?s?|the)?\s*(.+)/i',
            '/(?:incorrect|wrong),?\s*(?:it\'?s?|that\'?s?|the)?\s*(.+)/i',
            '/(?:not quite),?\s*(.+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches)) {
                return trim($matches[1]);
            }
        }

        // Fallback: return everything after the trigger
        $pos = stripos($message, $trigger);
        if ($pos !== false) {
            return trim(substr($message, $pos + strlen($trigger)));
        }

        return null;
    }

    /**
     * Clean and format content for storage.
     */
    protected function cleanContent(string $content): string
    {
        // Remove quotes
        $content = trim($content, '"\' ');

        // Capitalize first letter
        $content = ucfirst($content);

        // Ensure ends with period
        if (!preg_match('/[.!?]$/', $content)) {
            $content .= '.';
        }

        return $content;
    }

    /**
     * Determine the default tier for knowledge.
     */
    protected function determineDefaultTier(string $type, string $content): string
    {
        // Instructions and corrections with strong words are critical
        if ($type === 'instruction' || $type === 'correction') {
            $criticalPatterns = [
                '/\bnever\b/i',
                '/\balways\b/i',
                '/\bmust\b/i',
                '/\bdon\'t\b/i',
                '/\bdo not\b/i',
                '/\bprohibited\b/i',
                '/\bforbidden\b/i',
                '/\brequired\b/i',
            ];

            foreach ($criticalPatterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    return 'critical';
                }
            }
        }

        return 'contextual';
    }

    /**
     * Determine the knowledge type based on detection.
     *
     * @param  array<string, mixed>  $detection
     */
    protected function determineKnowledgeType(array $detection): string
    {
        if ($detection['type'] === 'correction') {
            return 'fact';
        }

        if ($detection['type'] === 'explicit') {
            // If it contains instruction words, it's an instruction
            if (preg_match('/\b(always|never|must|should|don\'t)\b/i', $detection['content'])) {
                return 'instruction';
            }
        }

        return 'fact';
    }

    /**
     * Generate a title from content.
     */
    protected function generateTitle(string $content): string
    {
        // Take first 60 characters, truncate at word boundary
        $title = substr($content, 0, 60);

        if (strlen($content) > 60) {
            $lastSpace = strrpos($title, ' ');
            if ($lastSpace !== false) {
                $title = substr($title, 0, $lastSpace);
            }
            $title .= '...';
        }

        return $title;
    }
}
