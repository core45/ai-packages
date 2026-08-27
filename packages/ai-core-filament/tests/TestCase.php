<?php

declare(strict_types=1);

namespace Core45\AiCore\Filament\Tests;

use Core45\AiCore\AiCoreServiceProvider;
use Core45\AiCore\Filament\AiCoreFilamentServiceProvider;
use Orchestra\Testbench\TestCase as OrchTestCase;

/**
 * Boots this package alongside the domain package it decorates, in the order
 * Composer would register them — `ai-core` first, since `ai-core-filament`
 * requires it. Binding precedence depends on that order, so a harness that got
 * it backwards would prove the opposite of what the tests assert.
 */
abstract class TestCase extends OrchTestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AiCoreServiceProvider::class,
            AiCoreFilamentServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.base_url', 'https://phasea.test');
        $app['config']->set('ai.token', 'test-token');
    }
}
