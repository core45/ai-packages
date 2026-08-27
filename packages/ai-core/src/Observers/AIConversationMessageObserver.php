<?php

declare(strict_types=1);

namespace Core45\AiCore\Observers;

use Core45\AiCore\Enums\AIFeedbackRating;
use Core45\AiCore\Models\AIConversationMessage;
use Stevebauman\Purify\Facades\Purify;

class AIConversationMessageObserver
{
    /**
     * Handle the AIConversationMessage "creating" event.
     *
     * Sanitizes user input as a defense-in-depth measure.
     * Primary sanitization happens in Livewire components, but this
     * catches any messages created through other code paths.
     */
    public function creating(AIConversationMessage $message): void
    {
        // Sanitize user messages as a safety net
        if ($message->role === 'user' && $message->content !== '') {
            /** @var string $sanitized */
            $sanitized = Purify::clean($message->content);
            $message->content = $sanitized;
        }

        // Sanitize feedback reason if present during creation
        if ($message->feedback_reason !== null && $message->feedback_reason !== '') {
            /** @var string $sanitizedReason */
            $sanitizedReason = Purify::clean($message->feedback_reason);
            $message->feedback_reason = $sanitizedReason;
        }
    }

    /**
     * Handle the AIConversationMessage "updating" event.
     *
     * Sanitizes feedback_reason when it's being updated.
     */
    public function updating(AIConversationMessage $message): void
    {
        // Sanitize feedback reason when it changes
        if ($message->isDirty('feedback_reason') && $message->feedback_reason !== null && $message->feedback_reason !== '') {
            /** @var string $sanitizedReason */
            $sanitizedReason = Purify::clean($message->feedback_reason);
            $message->feedback_reason = $sanitizedReason;
        }
    }

    /**
     * Handle the AIConversationMessage "updated" event.
     *
     * Triggers feedback analysis when negative feedback is given.
     */
    public function updated(AIConversationMessage $message): void
    {
        // Check if feedback_rating was just changed to negative
        if (!$this->wasJustMarkedNegative($message)) {
            return;
        }

        // Only analyze assistant messages (users give feedback on AI responses)
        if (!$message->isFromAssistant()) {
            return;
        }

        // Dispatch analysis job asynchronously. The feedback-analysis loop is
        // consumer-owned (plan A3/A13 — the admin assistant stack stays
        // host-side), so the job class comes from config and the package names
        // no host class of its own. Unconfigured means "this app has no
        // feedback loop", not an error.
        $analyzeJob = config('ai.analyze_feedback_job');

        if (!is_string($analyzeJob) || $analyzeJob === '') {
            return;
        }

        $analyzeJob::dispatch($message);
    }

    /**
     * Check if the message was just marked as negative feedback.
     */
    protected function wasJustMarkedNegative(AIConversationMessage $message): bool
    {
        // Check if feedback_rating changed
        if (!$message->wasChanged('feedback_rating')) {
            return false;
        }

        // Check if the new value is negative
        return $message->feedback_rating === AIFeedbackRating::Negative;
    }
}
