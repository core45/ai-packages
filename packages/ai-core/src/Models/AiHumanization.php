<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $humanizable_type
 * @property int $humanizable_id
 * @property string $locale
 * @property string $field
 * @property string $original_text
 * @property string|null $humanized_text
 * @property string $status
 * @property string|null $external_id
 * @property string|null $operation_id
 * @property string|null $client_reference
 * @property string|null $phasea_status
 * @property string|null $error_code
 * @property Carbon|null $poll_give_up_at
 * @property string|null $request_hash
 * @property string|null $active_intent_key
 * @property string $submission_state
 * @property array{readability: string, purpose: string, strength: string}|null $resolved_options
 * @property Carbon|null $written_back_at
 * @property int $poll_failures
 * @property Carbon|null $last_poll_failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiHumanization extends Model
{
    protected $fillable = [
        'humanizable_type',
        'humanizable_id',
        'locale',
        'field',
        'original_text',
        'humanized_text',
        'status',
        'external_id',
        'operation_id',
        'client_reference',
        'phasea_status',
        'error_code',
        'poll_give_up_at',
        'request_hash',
        'active_intent_key',
        'submission_state',
        'resolved_options',
        'written_back_at',
        'poll_failures',
        'last_poll_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'humanized_text' => 'string',
            'status' => 'string',
            'poll_give_up_at' => 'datetime',
            'resolved_options' => 'array',
            'written_back_at' => 'datetime',
            'poll_failures' => 'integer',
            'last_poll_failed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function humanizable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphMany<AiOperationContinuation, $this>
     */
    public function continuations(): MorphMany
    {
        return $this->morphMany(AiOperationContinuation::class, 'operation');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
