<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Models\SeoIndexable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeoIndexable>
 */
class SeoIndexableFactory extends Factory
{
    protected $model = SeoIndexable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'indexable_type' => 'post',
            'indexable_id' => fake()->numberBetween(1, 1000),
            'locale' => 'en',
            'title' => fake()->sentence(),
            'description' => fake()->text(150),
            'canonical' => fake()->url(),
            'robots' => 'index, follow',
            'is_noindex' => false,
            'seo_score' => fake()->numberBetween(0, 100),
            'readability_score' => fake()->numberBetween(0, 100),
            'overall_status' => 'good',
            'internal_links_inbound' => 0,
            'internal_links_outbound' => 0,
            'is_cornerstone' => false,
        ];
    }

    public function cornerstone(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_cornerstone' => true,
        ]);
    }

    public function noindex(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_noindex' => true,
        ]);
    }
}
