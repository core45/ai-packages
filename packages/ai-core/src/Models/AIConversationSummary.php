<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Contracts\AIQueryable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $summary_date
 * @property string $period_type
 * @property string|null $locale
 * @property int $conversation_count
 * @property int $message_count
 * @property int $user_message_count
 * @property array<int, string> $top_topics
 * @property array<int, string> $common_questions
 * @property string|null $ai_summary
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AIConversationSummary extends Model implements AIQueryable
{
    protected $table = 'ai_conversation_summaries';

    protected $fillable = [
        'summary_date',
        'period_type',
        'locale',
        'conversation_count',
        'message_count',
        'user_message_count',
        'top_topics',
        'common_questions',
        'ai_summary',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'summary_date' => 'date',
            'top_topics' => 'array',
            'common_questions' => 'array',
            'metadata' => 'array',
            'conversation_count' => 'integer',
            'message_count' => 'integer',
            'user_message_count' => 'integer',
        ];
    }

    // Scopes

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeDaily(Builder $query): Builder
    {
        return $query->where('period_type', 'daily');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeWeekly(Builder $query): Builder
    {
        return $query->where('period_type', 'weekly');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('period_type', 'monthly');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForLocale(Builder $query, string $locale): Builder
    {
        return $query->where('locale', $locale);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('summary_date', '>=', now()->subDays($days));
    }

    // AIQueryable implementation

    /**
     * @return array<int, string>
     */
    public function getAIQueryableFields(): array
    {
        return [
            'id',
            'summary_date',
            'period_type',
            'locale',
            'conversation_count',
            'message_count',
            'user_message_count',
            'top_topics',
            'common_questions',
            'ai_summary',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getAIFilterScopes(): array
    {
        return [
            'daily' => 'Daily summaries',
            'weekly' => 'Weekly summaries',
            'monthly' => 'Monthly summaries',
            'recent' => 'Last 30 days',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAIActions(): array
    {
        return []; // Read-only
    }

    public function getFilamentResource(): ?string
    {
        return null;
    }

    public static function getAIModelLabel(): string
    {
        return 'Conversation Summary';
    }

    public static function getAIModelPluralLabel(): string
    {
        return 'Conversation Summaries';
    }
}
