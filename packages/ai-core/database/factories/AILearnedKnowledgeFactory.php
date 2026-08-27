<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Models\AILearnedKnowledge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AILearnedKnowledge>
 */
class AILearnedKnowledgeFactory extends Factory
{
    protected $model = AILearnedKnowledge::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $types = ['instruction', 'fact', 'faq'];
        $tiers = ['critical', 'contextual'];
        $audiences = ['public', 'customer', 'admin'];

        return [
            'type' => fake()->randomElement($types),
            'tier' => fake()->randomElement($tiers),
            'audience' => fake()->randomElement($audiences),
            'title' => fake()->sentence(),
            'content' => fake()->paragraphs(3, true),
            'keywords' => fake()->optional()->randomElements(['product', 'service', 'support', 'shipping', 'warranty'], fake()->numberBetween(1, 3)),
            'source_type' => 'manual',
            'enabled' => true,
            'created_by' => null,
            'use_count' => 0,
            'last_used_at' => null,
        ];
    }

    /**
     * Indicate that the knowledge is disabled.
     */
    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'enabled' => false,
        ]);
    }

    /**
     * Indicate that the knowledge is critical tier.
     */
    public function critical(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'critical',
        ]);
    }

    /**
     * Indicate that the knowledge is contextual tier.
     */
    public function contextual(): static
    {
        return $this->state(fn (array $attributes) => [
            'tier' => 'contextual',
        ]);
    }

    /**
     * Indicate that the knowledge is for a specific audience.
     */
    public function forAudience(string $audience): static
    {
        return $this->state(fn (array $attributes) => [
            'audience' => $audience,
        ]);
    }

    /**
     * Indicate that the knowledge is of a specific type.
     */
    public function ofType(string $type): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => $type,
        ]);
    }
}
