<?php

declare(strict_types=1);

namespace Core45\AiCore\Tests;

use Core45\AiCore\AiCoreServiceProvider;
use Orchestra\Testbench\TestCase as OrchTestCase;

/**
 * D12 two-layer testing: per-package Testbench harness. Boots ONLY this
 * package's providers — no host app, no host service providers — which is
 * exactly how a second consuming application would experience the package.
 */
abstract class TestCase extends OrchTestCase
{
    protected function getPackageProviders($app): array
    {
        return [AiCoreServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('ai.base_url', 'https://phasea.test');
        $app['config']->set('ai.token', 'test-token');
    }
}
