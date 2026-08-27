<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Models\AIContentBatch;
use Core45\AiCore\Support\HostFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AIContentBatch>
 */
class AIContentBatchFactory extends Factory
{
    protected $model = AIContentBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $itemIds = [fake()->unique()->numberBetween(1, 1000), fake()->unique()->numberBetween(1, 1000)];

        return [
            'user_id' => HostFactory::forClass(self::userModel()),
            'batchable_type' => config('ai.factories.batchable_type', self::userModel()),
            'total_items' => count($itemIds),
            'processed_items' => 0,
            'failed_items' => 0,
            'item_ids' => $itemIds,
            'status' => 'processing',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processed_items' => $attributes['total_items'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'failed_items' => $attributes['total_items'],
        ]);
    }

    /**
     * The consuming app's authenticatable model class.
     *
     * @return class-string<Model>
     */
    protected static function userModel(): string
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return $model;
    }
}
