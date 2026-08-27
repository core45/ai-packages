<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Models\AIKnowledgeGap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AIKnowledgeGap>
 */
class AIKnowledgeGapFactory extends Factory
{
    protected $model = AIKnowledgeGap::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'question' => fake()->sentence().'?',
            'ai_response' => "I'm sorry, I don't have information about that. ".fake()->sentence(),
            'confidence_score' => fake()->randomFloat(2, 0, 0.39),
            'audience' => fake()->randomElement(['public', 'customer', 'admin']),
            'conversation_id' => null,
            'occurrence_count' => fake()->numberBetween(1, 10),
            'last_occurred_at' => fake()->dateTimeBetween('-1 week', 'now'),
            'similar_questions' => null,
            'status' => 'open',
            'resolved_by' => null,
            'resolved_at' => null,
            'resolution_knowledge_id' => null,
        ];
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'open',
            'resolved_by' => null,
            'resolved_at' => null,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'resolved',
            'resolved_at' => now(),
        ]);
    }

    public function ignored(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'ignored',
            'resolved_at' => now(),
        ]);
    }

    public function frequent(): static
    {
        return $this->state(fn (array $attributes) => [
            'occurrence_count' => fake()->numberBetween(5, 20),
        ]);
    }

    public function forAudience(string $audience): static
    {
        return $this->state(fn (array $attributes) => [
            'audience' => $audience,
        ]);
    }
}
