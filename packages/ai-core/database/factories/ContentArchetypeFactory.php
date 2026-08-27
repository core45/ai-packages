<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Models\ContentArchetype;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentArchetype>
 */
class ContentArchetypeFactory extends Factory
{
    protected $model = ContentArchetype::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'applicable_model' => 'Post',
            'description' => fake()->sentence(),
            'system_prompt' => fake()->paragraph(),
            'sections_config' => [],
            'tone' => 'professional',
            'formality' => 'formal',
            'is_default' => false,
            'active' => true,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
