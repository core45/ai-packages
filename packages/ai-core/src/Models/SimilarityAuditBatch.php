<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;

/**
 * @property int $id
 * @property string $model_type
 * @property int $total_items
 * @property int $processed_items
 * @property array<string, mixed> $options
 * @property int $user_id
 * @property string $status
 * @property array<int, array{item_id: int, field: string, locale: string, score: float, similar_to: string}>|null $issues
 * @property int $issues_count
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 */
class SimilarityAuditBatch extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /** @var list<string> */
    protected $fillable = [
        'model_type',
        'total_items',
        'processed_items',
        'options',
        'user_id',
        'status',
        'issues',
        'issues_count',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'options' => 'array',
            'issues' => 'array',
            'issues_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the short type name for display (e.g., 'Post' from the model's FQCN).
     */
    public function getTypeLabel(): string
    {
        return class_basename($this->model_type);
    }

    /**
     * Get the plural type label for display.
     */
    public function getTypePluralLabel(): string
    {
        return str($this->getTypeLabel())->plural()->toString();
    }

    public function isComplete(): bool
    {
        return $this->processed_items >= $this->total_items;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }

    public function incrementProcessed(): void
    {
        $this->increment('processed_items');
    }

    /**
     * Add a similarity issue to the batch.
     *
     * @param  array{item_id: int, field: string, locale: string, score: float, similar_to: string, similar_type: string}  $issue
     */
    public function addIssue(array $issue): void
    {
        $issues = $this->issues ?? [];
        $issues[] = $issue;

        $this->update([
            'issues' => $issues,
            'issues_count' => count($issues),
        ]);
    }

    /**
     * Get issues filtered by status (FAIL = score >= 0.75).
     *
     * @return array<int, array{item_id: int, field: string, locale: string, score: float, similar_to: string}>
     */
    public function getFailedIssues(): array
    {
        return array_filter($this->issues ?? [], fn (array $issue): bool => $issue['score'] >= 0.75);
    }

    /**
     * Get warning issues (score 0.60 - 0.74).
     *
     * @return array<int, array{item_id: int, field: string, locale: string, score: float, similar_to: string}>
     */
    public function getWarningIssues(): array
    {
        return array_filter(
            $this->issues ?? [],
            fn (array $issue): bool => $issue['score'] >= 0.60 && $issue['score'] < 0.75
        );
    }

    /**
     * Get progress percentage.
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_items === 0) {
            return 0.0;
        }

        return ($this->processed_items / $this->total_items) * 100;
    }

    /**
     * Get formatted duration of the audit.
     */
    public function getDuration(): ?string
    {
        if ($this->started_at === null) {
            return null;
        }

        $endTime = $this->completed_at ?? now();

        return $this->started_at->diffForHumans($endTime, ['syntax' => CarbonInterface::DIFF_ABSOLUTE]);
    }

    /**
     * Check if audit has any issues.
     */
    public function hasIssues(): bool
    {
        return $this->issues_count > 0;
    }

    /**
     * @param  Builder<SimilarityAuditBatch>  $query
     * @return Builder<SimilarityAuditBatch>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * @param  Builder<SimilarityAuditBatch>  $query
     * @return Builder<SimilarityAuditBatch>
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    /**
     * @param  Builder<SimilarityAuditBatch>  $query
     * @return Builder<SimilarityAuditBatch>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * @param  Builder<SimilarityAuditBatch>  $query
     * @return Builder<SimilarityAuditBatch>
     */
    public function scopeWithIssues(Builder $query): Builder
    {
        return $query->where('issues_count', '>', 0);
    }

    /**
     * @param  Builder<SimilarityAuditBatch>  $query
     * @return Builder<SimilarityAuditBatch>
     */
    public function scopeForModelType(Builder $query, string $modelType): Builder
    {
        return $query->where('model_type', $modelType);
    }

    /**
     * Create an audit batch for a specific model type.
     *
     * @param  class-string  $modelClass
     * @param  array<string, mixed>  $options
     */
    public static function createForModel(string $modelClass, int $totalItems, int $userId, array $options = []): self
    {
        return self::create([
            'model_type' => $modelClass,
            'total_items' => $totalItems,
            'processed_items' => 0,
            'options' => $options,
            'user_id' => $userId,
            'status' => self::STATUS_PENDING,
            'issues' => [],
            'issues_count' => 0,
        ]);
    }
}
