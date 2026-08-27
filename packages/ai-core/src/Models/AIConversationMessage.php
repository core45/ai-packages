<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Carbon\Carbon;
use Core45\AiCore\Enums\AIFeedbackRating;
use Core45\AiCore\Enums\AIMessageStatus;
use Core45\AiCore\Observers\AIConversationMessageObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;

/**
 * @property int $id
 * @property int $ai_conversation_id
 * @property string|null $operation_id
 * @property string $role
 * @property string $content
 * @property AIMessageStatus $status
 * @property array|null $metadata
 * @property AIFeedbackRating|null $feedback_rating
 * @property string|null $feedback_reason
 * @property Carbon|null $feedback_at
 * @property bool $has_error
 * @property string|null $error_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[ObservedBy(AIConversationMessageObserver::class)]
class AIConversationMessage extends Model
{
    use Searchable;

    protected $table = 'ai_conversation_messages';

    protected $fillable = [
        'ai_conversation_id',
        'operation_id',
        'role',
        'content',
        'status',
        'metadata',
        'feedback_rating',
        'feedback_reason',
        'feedback_at',
        'has_error',
        'error_type',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'status' => AIMessageStatus::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'feedback_rating' => AIFeedbackRating::class,
            'feedback_at' => 'datetime',
            'has_error' => 'boolean',
        ];
    }

    /**
     * Set positive feedback (thumbs up).
     */
    public function markAsPositive(): void
    {
        $this->update([
            'feedback_rating' => AIFeedbackRating::Positive,
            'feedback_reason' => null,
            'feedback_at' => now(),
        ]);
    }

    /**
     * Set negative feedback (thumbs down) with optional reason.
     */
    public function markAsNegative(?string $reason = null): void
    {
        $this->update([
            'feedback_rating' => AIFeedbackRating::Negative,
            'feedback_reason' => $reason,
            'feedback_at' => now(),
        ]);
    }

    /**
     * Clear feedback.
     */
    public function clearFeedback(): void
    {
        $this->update([
            'feedback_rating' => null,
            'feedback_reason' => null,
            'feedback_at' => null,
        ]);
    }

    /**
     * Mark as error response (auto-flagged).
     */
    public function markAsError(string $errorType, ?string $errorMessage = null): void
    {
        $this->update([
            'has_error' => true,
            'error_type' => $errorType,
            'feedback_rating' => AIFeedbackRating::Negative,
            'feedback_reason' => $errorMessage ? "Auto-flagged error: {$errorMessage}" : "Auto-flagged error: {$errorType}",
            'feedback_at' => now(),
        ]);
    }

    /**
     * Check if message has feedback.
     */
    public function hasFeedback(): bool
    {
        return $this->feedback_rating !== null;
    }

    /**
     * Check if message has positive feedback.
     */
    public function isPositive(): bool
    {
        return $this->feedback_rating === AIFeedbackRating::Positive;
    }

    /**
     * Check if message has negative feedback.
     */
    public function isNegative(): bool
    {
        return $this->feedback_rating === AIFeedbackRating::Negative;
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AIConversation::class, 'ai_conversation_id');
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function isFromUser(): bool
    {
        return $this->role === 'user';
    }

    public function isFromAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    // Scout/Meilisearch configuration

    public function searchableAs(): string
    {
        return config('scout.prefix').'customer_messages';
    }

    /**
     * Only index customer/anonymous conversation messages (not admin).
     */
    public function shouldBeSearchable(): bool
    {
        $conversation = $this->conversation;

        if (!$conversation) {
            return false;
        }

        return in_array($conversation->audience, ['customer', 'anonymous']);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $conversation = $this->conversation;

        return [
            'id' => $this->id,
            'content' => $this->content,
            'role' => $this->role,
            'conversation_id' => $this->ai_conversation_id,
            'audience' => $conversation?->audience,
            'locale' => $conversation?->locale,
            'created_at' => $this->created_at?->timestamp,
        ];
    }
}
