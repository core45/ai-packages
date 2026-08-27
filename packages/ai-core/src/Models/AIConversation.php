<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Contracts\AIQueryable;
use Core45\AiCore\Database\Factories\AIConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Foundation\Auth\User;

class AIConversation extends Model implements AIQueryable
{
    use HasFactory;

    /** Package-local factory resolution (consumer apps have no factory for this model). */
    protected static function newFactory(): AIConversationFactory
    {
        return AIConversationFactory::new();
    }

    protected $table = 'ai_conversations';

    protected $fillable = [
        'user_id',
        'model_type',
        'model_id',
        'field',
        'locale',
        'status',
        'audience',
        'session_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AIConversationMessage::class, 'ai_conversation_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<AIConversation>  $query
     * @return Builder<AIConversation>
     */
    public function scopeForAudience($query, string $audience)
    {
        return $query->where('audience', $audience);
    }

    /**
     * @param  Builder<AIConversation>  $query
     * @return Builder<AIConversation>
     */
    public function scopeForSession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    /**
     * @param  Builder<AIConversation>  $query
     * @return Builder<AIConversation>
     */
    public function scopeCustomer($query)
    {
        return $query->whereIn('audience', ['customer', 'anonymous']);
    }

    /**
     * @param  Builder<AIConversation>  $query
     * @return Builder<AIConversation>
     */
    public function scopeAdmin($query)
    {
        return $query->where('audience', 'admin');
    }

    public function getLastMessageAttribute(): ?AIConversationMessage
    {
        return $this->messages()->latest()->first();
    }

    // AIQueryable implementation

    /**
     * @return array<int, string>
     */
    public function getAIQueryableFields(): array
    {
        return ['id', 'audience', 'status', 'locale', 'created_at'];
    }

    /**
     * @return array<string, string>
     */
    public function getAIFilterScopes(): array
    {
        return [
            'customer' => 'Customer and anonymous conversations (from public chat)',
            'admin' => 'Admin panel conversations',
            'active' => 'Active conversations only',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAIActions(): array
    {
        return []; // Read-only, no actions
    }

    /**
     * Get relations to eager load for AI queries.
     *
     * @return array<int, string>
     */
    public function getAIEagerLoadRelations(): array
    {
        return ['messages'];
    }

    public function getFilamentResource(): ?string
    {
        return null; // No Filament resource for conversations
    }

    public static function getAIModelLabel(): string
    {
        return 'Customer Conversation';
    }

    public static function getAIModelPluralLabel(): string
    {
        return 'Customer Conversations';
    }
}
