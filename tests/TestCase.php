<?php

declare(strict_types=1);

namespace Digit7s\PulseCacheHealth\Tests;

use Digit7s\PulseCacheHealth\PulseCacheHealthServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [PulseCacheHealthServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
            'url' => null,
        ]);
    }
}
