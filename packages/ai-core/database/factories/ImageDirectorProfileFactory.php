<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Models\ImageDirectorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImageDirectorProfile>
 */
class ImageDirectorProfileFactory extends Factory
{
    protected $model = ImageDirectorProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'slug' => fake()->unique()->slug(2),
            'applicable_model' => null,
            'description' => fake()->sentence(),
            'system_prompt' => fake()->paragraph(),
            'style_guidance' => fake()->sentence(),
            'default_image_style_id' => null,
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
