<?php

declare(strict_types=1);

namespace Core45\AiCore\Support;

use Core45\AiCore\ModelRegistry;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Build a factory for a HOST model resolved through the registry.
 *
 * Package factories need host records for their foreign keys — a feedback row
 * needs a post and a user. The registry hands back a `class-string<Model>`, and
 * `Model` declares no static `factory()`: `HasFactory` is a trait, not part of
 * any contract, and nothing in `AIContentable` requires it. So
 * `$class::factory()` is a call the type system cannot justify and a consumer
 * can genuinely fail to satisfy.
 *
 * §7 calls this out as the recurring shape: the first host works by luck, and
 * the failure lands on the SECOND one as a fatal. Here it would surface as
 * "Call to undefined method <the registered post model>::factory()" from inside
 * a package factory — a message naming neither the registry key nor the
 * obligation.
 *
 * This states the obligation instead: models registered in `config('ai.models')`
 * must use `HasFactory` IF the consuming app runs the package's tests. It is a
 * test-time requirement only; nothing in production resolves this class.
 */
final class HostFactory
{
    /**
     * @return Factory<Model>
     *
     * @throws RuntimeException when the registered model cannot build a factory
     */
    public static function for(string $registryKey): Factory
    {
        return self::forClass(
            app(ModelRegistry::class)->getModelClassOrFail($registryKey),
            $registryKey,
        );
    }

    /**
     * The same guarantee for a class resolved some other way. ai-core's own
     * factories read the user model straight out of
     * `config('auth.providers.users.model')` rather than from the registry, and
     * hit the identical gap.
     *
     * @param  class-string<Model>  $class
     * @return Factory<Model>
     *
     * @throws RuntimeException when the model cannot build a factory
     */
    public static function forClass(string $class, ?string $describedAs = null): Factory
    {
        if (!method_exists($class, 'factory')) {
            throw new RuntimeException(sprintf(
                'The host model %s has no factory(). Package factories need one to build '
                .'host records for their foreign keys, so %s must use HasFactory if this '
                .'app runs the package test suite.',
                $class,
                $describedAs !== null
                    ? sprintf('the model registered as "%s"', $describedAs)
                    : 'it',
            ));
        }

        /** @var Factory<Model> $factory */
        $factory = $class::factory();

        return $factory;
    }
}
