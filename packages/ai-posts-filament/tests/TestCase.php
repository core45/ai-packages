<?php

declare(strict_types=1);

namespace Core45\AiPosts\Filament\Tests;

use Core45\AiCore\AiCoreServiceProvider;
use Core45\AiCore\Filament\AiCoreFilamentServiceProvider;
use Core45\AiPosts\AiPostsServiceProvider;
use Core45\AiPosts\Filament\AiPostsFilamentServiceProvider;
use Orchestra\Testbench\TestCase as OrchTestCase;

/**
 * Boots the full chain this package sits on top of, in the order Composer would
 * register it: `ai-core`, then `ai-core-filament`, then `ai-posts`, then this.
 *
 * The order is not cosmetic — binding precedence follows registration order, so
 * a harness that got it wrong would prove something other than what ships.
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
            AiPostsServiceProvider::class,
            AiPostsFilamentServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.base_url', 'https://phasea.test');
        $app['config']->set('ai.token', 'test-token');
    }
}
