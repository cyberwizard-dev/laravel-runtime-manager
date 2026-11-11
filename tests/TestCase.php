<?php

namespace Cyberwizard\RuntimeManager\Tests;

use Cyberwizard\RuntimeManager\RuntimeManagerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [RuntimeManagerServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $config = $app['config'];

        // Encryption/session key for HTTP (dashboard) tests.
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // In-memory SQLite so the package migrations run fast and isolated.
        $config->set('database.default', 'testbench');
        $config->set('database.connections.testbench', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // Use the default (array) cache store for the tick lock in tests; the
        // array store supports atomic locks, which is what LockManager needs.
        $config->set('cache.default', 'array');
        $config->set('runtime-manager.lock.cache_store', null);
    }
}
