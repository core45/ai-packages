<?php

declare(strict_types=1);

namespace Core45\AiChat\Services;

use Core45\AiChat\Contracts\CustomerContextContract;
use Core45\AiCore\Contracts\AiClient;
use Core45\AiCore\Enums\AiSurface;
use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Models\AIConversationMessage;
use Core45\AiCore\Services\AiClientFactory;
use Core45\AiCore\Services\GapDetectionService;
use Core45\AiCore\Services\KnowledgeService;
use Core45\AiCore\Services\LearningDetectorService;
use Core45\AiCore\Services\PreferenceInferenceService;
use Core45\AiCore\Services\RemoteAiClient;
use Core45\AiCore\Traits\LogsAIOperations;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CustomerAIService
{
    use LogsAIOperations;

    protected string $provider;

    protected string $model;

    /**
     * `AiClient` is injected as the parity seam (SEAM-08) but not yet called:
     * `CustomerAIService::chat()`'s flow (filter -> gap-detection -> learning ->
     * usage-logging around a direct `Prism::text()` call) has no atomic
     * `AiClient::chat()` analog, and `AiClient::chat()` routes through a
     * different service (`AIAssistantService`) — routing here would break
     * behaviour under driver=local. Same contract-gap category as 08-15/08-16.
     * Real routing is deferred to the Phase 10 cutover.
     */
    public function __construct(
        protected CustomerContextContract $contextService,
        protected KnowledgeService $knowledgeService,
        protected GapDetectionService $gapDetectionService,
        protected LearningDetectorService $learningDetectorService,
        protected PreferenceInferenceService $preferenceInferenceService,
        protected AiClient $client,
    ) {
        // v2.6: $provider/$model removed. They were assigned here and never read
        // — the host does not choose models; phasea routes the customer_assistant
        // task. The customer_assistant.provider/model config keys they read were
        // deleted in 2b08d97.
    }

    /**
     * Check if customer AI assistant is enabled.
     */
    public function isEnabled(): bool
    {
        return config('ai.assistant.enabled', false)
            && config('ai.assistant.customer_assistant.enabled', true);
    }

    /**
     * Retired (Phase 16, CUT-09): zero live callers confirmed by grep —
     * `CustomerAIChatPanel::sendMessageSync()` routes through
     * `AiClientFactory::for(AiSurface::CustomerChat)->chat()` (the
     * `RemoteAiClient` seam) directly, not this method (Phase 12).
     *
     * @return never
     */
    public function chat(string $message, ?int $conversationId = null, ?string $sessionId = null): array
    {
        throw new \RuntimeException(
            'CustomerAIService::chat() is retired (Phase 16, CUT-09) — use '.
            'AiClientFactory::for(AiSurface::CustomerChat)->chat() directly.'
        );
    }

    /**
     * Get welcome message based on authentication status.
     */
    public function getWelcomeMessage(): string
    {
        $customer = Auth::user();

        if ($customer) {
            return __('customer_ai.welcome_authenticated', ['name' => $customer->name]);
        }

        return __('customer_ai.welcome_anonymous');
    }

    /**
     * Find existing conversation.
     */
    protected function findConversation(int $conversationId, ?User $customer, ?string $sessionId): ?AIConversation
    {
        $query = AIConversation::query()
            ->customer()
            ->where('id', $conversationId);

        if ($customer) {
            $query->where('user_id', $customer->id);
        } elseif ($sessionId) {
            $query->where('session_id', $sessionId)
                ->whereNull('user_id');
        } else {
            return null;
        }

        return $query->first();
    }

    /**
     * Create a new conversation.
     */
    protected function createConversation(?User $customer, ?string $sessionId, string $audience): AIConversation
    {
        return AIConversation::create([
            'user_id' => $customer?->id,
            'session_id' => $customer ? null : $sessionId,
            'audience' => $audience,
            'locale' => app()->getLocale(),
            'status' => 'active',
        ]);
    }

    /**
     * Add message to conversation.
     *
     * Saves to database first (without Scout sync), then attempts search indexing separately.
     * This ensures chat works even when Meilisearch is unavailable.
     *
     * @param  array<string, mixed>  $metadata
     */
    protected function addMessage(AIConversation $conversation, string $role, string $content, array $metadata = []): AIConversationMessage
    {
        // Create message without triggering Scout sync (to prevent blocking on search failures)
        /** @var AIConversationMessage $message */
        $message = AIConversationMessage::withoutSyncingToSearch(function () use ($conversation, $role, $content, $metadata): AIConversationMessage {
            /** @var AIConversationMessage */
            return $conversation->messages()->create([
                'role' => $role,
                'content' => $content,
                'metadata' => $metadata ?: null,
            ]);
        });

        // Attempt to sync to search index separately (non-blocking)
        $this->syncMessageToSearch($message);

        return $message;
    }

    /**
     * Attempt to sync message to search index.
     *
     * Fails gracefully if search service is unavailable.
     */
    protected function syncMessageToSearch(AIConversationMessage $message): void
    {
        try {
            if ($message->shouldBeSearchable()) {
                $message->searchable();
            }
        } catch (\Exception $e) {
            // Other search errors - log but don't fail the request
            Log::channel('ai')->warning('Failed to index message to search', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Detect and record knowledge gaps when AI response indicates uncertainty.
     */
    protected function detectKnowledgeGap(
        string $userQuestion,
        string $aiResponse,
        string $audience,
        AIConversation $conversation
    ): void {
        try {
            // Map anonymous to public for gap tracking
            $gapAudience = $audience === 'anonymous' ? 'public' : $audience;

            $this->gapDetectionService->analyzeResponse(
                $userQuestion,
                $aiResponse,
                $gapAudience,
                $conversation
            );
        } catch (\Exception $e) {
            // Log but don't fail the response
            report($e);
        }
    }

    /**
     * Detect learning opportunities in user message.
     *
     * Checks for explicit commands ("remember that..."), corrections,
     * and information patterns that might be worth learning.
     *
     * @return array<string, mixed>|null
     */
    protected function detectLearningOpportunity(
        string $userMessage,
        AIConversation $conversation
    ): ?array {
        // Only detect learning for authenticated admin users
        // Customer learning detection is limited to explicit commands
        $customer = Auth::user();
        if (!$customer) {
            return null;
        }

        try {
            // Check for explicit learning commands ("remember that...", etc.)
            $explicitCommand = $this->learningDetectorService->detectExplicitCommand($userMessage);
            if ($explicitCommand) {
                return $this->learningDetectorService->createLearningSuggestion(
                    $explicitCommand,
                    $conversation
                );
            }

            // Check for corrections (needs previous AI response)
            $previousAiResponse = $this->getPreviousAiResponse($conversation);
            if ($previousAiResponse) {
                $correction = $this->learningDetectorService->detectCorrection(
                    $userMessage,
                    $previousAiResponse
                );
                if ($correction) {
                    return $this->learningDetectorService->createLearningSuggestion(
                        $correction,
                        $conversation
                    );
                }
            }

            // Check for business information patterns
            $suggestedLearning = $this->learningDetectorService->detectSuggestedLearning($userMessage);
            if ($suggestedLearning) {
                return $this->learningDetectorService->createLearningSuggestion(
                    $suggestedLearning,
                    $conversation
                );
            }
        } catch (\Exception $e) {
            // Log but don't fail the response
            $this->logError('LearningDetection', $e);
        }

        return null;
    }

    /**
     * Get the previous AI response from the conversation.
     */
    protected function getPreviousAiResponse(AIConversation $conversation): ?string
    {
        /** @var AIConversationMessage|null $lastAssistantMessage */
        $lastAssistantMessage = $conversation->messages()
            ->where('role', 'assistant')
            ->orderByDesc('created_at')
            ->first();

        return $lastAssistantMessage?->content;
    }
}
