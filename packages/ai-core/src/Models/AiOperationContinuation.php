<?php

declare(strict_types=1);

namespace Core45\AiCore\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $operation_type
 * @property int $operation_id
 * @property string $continuation_key
 * @property string $payload
 * @property Carbon|null $released_at
 * @property Carbon|null $dispatched_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AiOperationContinuation extends Model
{
    protected $fillable = [
        'operation_type',
        'operation_id',
        'continuation_key',
        'payload',
        'released_at',
        'dispatched_at',
    ];

    protected function casts(): array
    {
        return [
            'released_at' => 'datetime',
            'dispatched_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function operation(): MorphTo
    {
        return $this->morphTo();
    }
}
