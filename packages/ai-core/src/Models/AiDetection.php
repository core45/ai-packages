<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $detectable_type
 * @property int $detectable_id
 * @property string $locale
 * @property float|null $score
 * @property array<string, mixed>|null $result_details
 * @property int $word_count
 * @property string $status
 * @property string|null $external_id
 * @property string|null $operation_id
 * @property string|null $client_reference
 * @property string|null $phasea_status
 * @property string|null $error_code
 * @property Carbon|null $poll_give_up_at
 * @property string|null $request_text
 * @property string|null $request_hash
 * @property string|null $active_intent_key
 * @property string $submission_state
 * @property string|null $humanize_continuation_payload
 * @property int $poll_failures
 * @property Carbon|null $last_poll_failed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiDetection extends Model
{
    protected $fillable = [
        'detectable_type',
        'detectable_id',
        'locale',
        'score',
        'result_details',
        'word_count',
        'status',
        'external_id',
        'operation_id',
        'client_reference',
        'phasea_status',
        'error_code',
        'poll_give_up_at',
        'request_text',
        'request_hash',
        'active_intent_key',
        'submission_state',
        'humanize_continuation_payload',
        'poll_failures',
        'last_poll_failed_at',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'result_details' => 'array',
            'word_count' => 'integer',
            'poll_give_up_at' => 'datetime',
            'poll_failures' => 'integer',
            'last_poll_failed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function detectable(): MorphTo
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

    public function getScoreLabel(): string
    {
        if ($this->isFailed()) {
            return __('Failed');
        }

        if ($this->isPending() || $this->score === null) {
            return __('Pending');
        }

        return match (true) {
            $this->score < 50.0 => __('Human'),
            $this->score <= 60.0 => __('Possible AI'),
            default => __('AI Detected'),
        };
    }

    public function getScoreColor(): string
    {
        if ($this->isFailed()) {
            return 'danger';
        }

        if ($this->isPending() || $this->score === null) {
            return 'gray';
        }

        return match (true) {
            $this->score < 50.0 => 'success',
            $this->score <= 60.0 => 'warning',
            default => 'danger',
        };
    }
}
