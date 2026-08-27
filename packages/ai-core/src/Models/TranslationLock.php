<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $lockable_type
 * @property int $lockable_id
 * @property string $locale
 * @property string $lock_token
 * @property array<string, mixed>|null $snapshot
 * @property string $status
 * @property Carbon $expires_at
 */
class TranslationLock extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'lockable_type',
        'lockable_id',
        'locale',
        'lock_token',
        'snapshot',
        'status',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function lockable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public static function acquire(Model $model, string $locale, array $snapshot = [], int $ttlMinutes = 5): static
    {
        static::where('lockable_type', $model->getMorphClass())
            ->where('lockable_id', $model->getKey())
            ->where('locale', $locale)
            ->delete();

        /** @var static */
        return static::create([
            'lockable_type' => $model->getMorphClass(),
            'lockable_id' => $model->getKey(),
            'locale' => $locale,
            'lock_token' => Str::uuid()->toString(),
            'snapshot' => $snapshot,
            'status' => 'active',
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
    }

    public static function isLocked(Model $model, string $locale): bool
    {
        return static::where('lockable_type', $model->getMorphClass())
            ->where('lockable_id', $model->getKey())
            ->where('locale', $locale)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->exists();
    }

    public static function isCancelledByToken(string $token): bool
    {
        $lock = static::where('lock_token', $token)->first();

        if (!$lock) {
            return true;
        }

        if ($lock->expires_at->isPast()) {
            return true;
        }

        return $lock->status === 'cancelled';
    }

    public static function cancelAndRestore(Model $model, string $locale): void
    {
        $lock = static::where('lockable_type', $model->getMorphClass())
            ->where('lockable_id', $model->getKey())
            ->where('locale', $locale)
            ->first();

        if (!$lock) {
            return;
        }

        if ($lock->snapshot && method_exists($model, 'restoreTranslationSnapshot')) {
            $model->restoreTranslationSnapshot($locale, $lock->snapshot);
        }

        $lock->delete();
    }

    public static function restoreSnapshot(string $token): void
    {
        $lock = static::where('lock_token', $token)->first();

        if (!$lock || !$lock->snapshot) {
            return;
        }

        $model = $lock->lockable;

        if ($model && method_exists($model, 'restoreTranslationSnapshot')) {
            $model->restoreTranslationSnapshot($lock->locale, $lock->snapshot);
        }
    }

    public static function releaseByToken(string $token): void
    {
        static::where('lock_token', $token)->delete();
    }

    public static function extendByToken(string $token, int $ttlMinutes = 5): void
    {
        static::where('lock_token', $token)->update([
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);
    }
}
