<?php

namespace Folklore\Mediatheque\Tests;

use Folklore\Mediatheque\ServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @param  Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
    }

    /**
     * Path of a file in tests/fixtures.
     */
    protected static function fixturePath(string $path = ''): string
    {
        return __DIR__.'/fixtures'.($path !== '' ? '/'.$path : '');
    }
}
