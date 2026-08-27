<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Models\ImageStyle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageStyle>
 */
class ImageStyleFactory extends Factory
{
    protected $model = ImageStyle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'prompt_fragment' => fake()->sentence(),
            'sort_order' => fake()->numberBetween(0, 100),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'active' => false,
        ]);
    }
}
