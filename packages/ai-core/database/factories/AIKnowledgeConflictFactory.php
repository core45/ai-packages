<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Enums\AIConflictStatus;
use Core45\AiCore\Enums\AIConflictType;
use Core45\AiCore\Models\AIKnowledgeConflict;
use Core45\AiCore\Models\AILearnedKnowledge;
use Core45\AiCore\Support\HostFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AIKnowledgeConflict>
 */
class AIKnowledgeConflictFactory extends Factory
{
    protected $model = AIKnowledgeConflict::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'knowledge_a_id' => AILearnedKnowledge::factory(),
            'knowledge_b_id' => AILearnedKnowledge::factory(),
            'conflict_type' => fake()->randomElement(AIConflictType::cases()),
            'overlap_keywords' => fake()->words(fake()->numberBetween(3, 6)),
            'overlap_score' => fake()->randomFloat(2, 20, 80),
            'status' => AIConflictStatus::Detected,
            'resolved_by' => null,
            'resolved_at' => null,
            'resolution_notes' => null,
        ];
    }

    public function detected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AIConflictStatus::Detected,
        ]);
    }

    public function reviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AIConflictStatus::Reviewed,
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AIConflictStatus::Resolved,
            'resolved_by' => HostFactory::forClass(self::userModel()),
            'resolved_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AIConflictStatus::Dismissed,
            'resolved_by' => HostFactory::forClass(self::userModel()),
            'resolved_at' => now(),
        ]);
    }

    public function directContradiction(): static
    {
        return $this->state(fn (array $attributes) => [
            'conflict_type' => AIConflictType::DirectContradiction,
        ]);
    }

    public function partialOverlap(): static
    {
        return $this->state(fn (array $attributes) => [
            'conflict_type' => AIConflictType::PartialOverlap,
        ]);
    }

    public function ambiguous(): static
    {
        return $this->state(fn (array $attributes) => [
            'conflict_type' => AIConflictType::Ambiguous,
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
