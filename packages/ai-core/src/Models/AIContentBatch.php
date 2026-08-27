<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Core45\AiCore\Database\Factories\AIContentBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property string $batchable_type
 * @property int $total_items
 * @property int $processed_items
 * @property int $failed_items
 * @property array<int> $item_ids
 * @property array<int>|null $failed_item_ids
 * @property array<int>|null $processed_item_ids
 * @property array<int, string>|null $error_messages
 * @property string $status
 * @property Carbon|null $notified_at
 * @property array<string, mixed>|null $media_options
 */
class AIContentBatch extends Model
{
    /** @use HasFactory<AIContentBatchFactory> */
    use HasFactory;

    /**
     * Package-local factory resolution: Laravel's default convention looks in
     * the consumer app's `Database\Factories` namespace first; point it back
     * at the package factory explicitly.
     */
    protected static function newFactory(): AIContentBatchFactory
    {
        return AIContentBatchFactory::new();
    }

    protected $table = 'ai_content_batches';

    protected $fillable = [
        'user_id',
        'batchable_type',
        'total_items',
        'processed_items',
        'failed_items',
        'item_ids',
        'failed_item_ids',
        'processed_item_ids',
        'error_messages',
        'status',
        'notified_at',
        'media_options',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_items' => 'integer',
            'processed_items' => 'integer',
            'failed_items' => 'integer',
            'item_ids' => 'array',
            'failed_item_ids' => 'array',
            'processed_item_ids' => 'array',
            'error_messages' => 'array',
            'media_options' => 'array',
            'notified_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getTypeLabel(): string
    {
        return class_basename($this->batchable_type);
    }

    public function getTypePluralLabel(): string
    {
        return str($this->getTypeLabel())->plural()->toString();
    }

    public function isComplete(): bool
    {
        return ($this->processed_items + $this->failed_items) >= $this->total_items;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, ['completed', 'completed_with_errors', 'failed', 'finalizing'], true);
    }

    public function isItemProcessed(int $itemId): bool
    {
        return in_array($itemId, $this->processed_item_ids ?? [], true);
    }

    public function isItemFailed(int $itemId): bool
    {
        return in_array($itemId, $this->failed_item_ids ?? [], true);
    }

    public function markAsProcessing(): void
    {
        $this->update(['status' => 'processing']);
    }

    public function markAsCompleted(): void
    {
        $this->update(['status' => 'completed']);
    }

    public function markAsFailed(): void
    {
        $this->update(['status' => 'failed']);
    }

    public function markAsCompletedWithErrors(): void
    {
        $this->update(['status' => 'completed_with_errors']);
    }

    /**
     * Idempotent, row-locked mark of an item as processed.
     * Returns true if this call actually added the item (first caller wins).
     */
    public function markItemProcessed(int $itemId): bool
    {
        return DB::transaction(function () use ($itemId): bool {
            /** @var self $locked */
            $locked = self::query()->lockForUpdate()->findOrFail($this->id);

            $processedIds = $locked->processed_item_ids ?? [];
            $failedIds = $locked->failed_item_ids ?? [];

            if (in_array($itemId, $processedIds, true) || in_array($itemId, $failedIds, true)) {
                return false;
            }

            $processedIds[] = $itemId;

            $locked->update([
                'processed_item_ids' => $processedIds,
                'processed_items' => count($processedIds),
            ]);

            $this->refresh();

            return true;
        });
    }

    /**
     * Idempotent, row-locked mark of an item as failed.
     * Returns true if this call actually added the item (first caller wins).
     */
    public function markItemFailed(int $itemId): bool
    {
        return DB::transaction(function () use ($itemId): bool {
            /** @var self $locked */
            $locked = self::query()->lockForUpdate()->findOrFail($this->id);

            $failedIds = $locked->failed_item_ids ?? [];
            $processedIds = $locked->processed_item_ids ?? [];

            if (in_array($itemId, $failedIds, true) || in_array($itemId, $processedIds, true)) {
                return false;
            }

            $failedIds[] = $itemId;

            $locked->update([
                'failed_item_ids' => $failedIds,
                'failed_items' => count($failedIds),
            ]);

            $this->refresh();

            return true;
        });
    }

    /**
     * Atomic claim for finalization — only one caller wins.
     */
    public function claimForFinalization(): bool
    {
        return self::query()
            ->where('id', $this->id)
            ->where('status', 'processing')
            ->update(['status' => 'finalizing']) === 1;
    }

    /**
     * Atomic claim for notification — only one caller wins.
     */
    public function claimForNotification(): bool
    {
        return self::query()
            ->where('id', $this->id)
            ->whereNull('notified_at')
            ->update(['notified_at' => now()]) === 1;
    }

    /**
     * Fail all remaining unprocessed items (circuit breaker abort path).
     */
    public function failRemainingItems(string $reason): void
    {
        DB::transaction(function () use ($reason): void {
            /** @var self $locked */
            $locked = self::query()->lockForUpdate()->findOrFail($this->id);

            $processedIds = $locked->processed_item_ids ?? [];
            $failedIds = $locked->failed_item_ids ?? [];
            $allItemIds = $locked->item_ids ?? [];
            $errors = $locked->error_messages ?? [];

            foreach ($allItemIds as $itemId) {
                if (!in_array($itemId, $processedIds, true) && !in_array($itemId, $failedIds, true)) {
                    $failedIds[] = $itemId;
                    $errors[$itemId] = $reason;
                }
            }

            $locked->update([
                'failed_item_ids' => $failedIds,
                'failed_items' => count($failedIds),
                'error_messages' => $errors,
            ]);

            $this->refresh();
        });

        if ($this->claimForFinalization()) {
            // The finalize job is consumer-owned (it edits host resources and
            // notifies host users); its class comes from config (D6). The
            // package ships no default, so an app without a finalize flow
            // simply completes the batch without dispatching.
            $finalizeJob = config('ai.finalize_job');

            if (is_string($finalizeJob) && $finalizeJob !== '') {
                $finalizeJob::dispatch($this);
            }
        }
    }

    public function getSuccessRate(): float
    {
        if ($this->total_items === 0) {
            return 0;
        }

        return ($this->processed_items / $this->total_items) * 100;
    }

    public function addErrorMessage(int $itemId, string $message): void
    {
        $errors = $this->error_messages ?? [];
        $errors[$itemId] = $message;
        $this->update(['error_messages' => $errors]);
    }

    public function hasErrors(): bool
    {
        return $this->failed_items > 0 || !empty($this->error_messages);
    }

    public function getErrorSummary(): string
    {
        if (empty($this->error_messages)) {
            return '';
        }

        $typeLabel = $this->getTypeLabel();
        $lines = [];

        foreach ($this->error_messages as $itemId => $message) {
            $lines[] = "- {$typeLabel} #{$itemId}: {$message}";
        }

        return implode("\n", $lines);
    }

    public function getErrorCount(): int
    {
        return count($this->error_messages ?? []);
    }

    /**
     * @param  class-string  $modelClass
     * @param  array<int>  $itemIds
     */
    public static function createForModel(string $modelClass, array $itemIds, int $userId): self
    {
        return self::create([
            'user_id' => $userId,
            'batchable_type' => $modelClass,
            'total_items' => count($itemIds),
            'processed_items' => 0,
            'failed_items' => 0,
            'item_ids' => $itemIds,
            'status' => 'processing',
        ]);
    }
}
