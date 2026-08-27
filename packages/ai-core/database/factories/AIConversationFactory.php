<?php

declare(strict_types=1);

namespace Core45\AiCore\Database\Factories;

use Core45\AiCore\Models\AIConversation;
use Core45\AiCore\Support\HostFactory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AIConversation>
 */
class AIConversationFactory extends Factory
{
    protected $model = AIConversation::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => HostFactory::forClass(self::userModel()),
            'locale' => fake()->randomElement(['en', 'pl', 'de', 'es', 'fr', 'it']),
            'status' => 'active',
            'audience' => 'admin',
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'audience' => 'admin',
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes) => [
            'audience' => 'customer',
        ]);
    }

    public function anonymous(?string $sessionId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
            'audience' => 'anonymous',
            'session_id' => $sessionId ?? fake()->uuid(),
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
