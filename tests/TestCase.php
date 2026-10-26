<?php

namespace Folklore\Mediatheque\Tests;

use Folklore\Mediatheque\ServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Symfony\Component\Process\ExecutableFinder;

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

        // SQLite in memory by default. CI also runs the tests against MySQL
        // and PostgreSQL: set the environment variable DB_DRIVER to `mysql`
        // or `pgsql`, with DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and
        // DB_PASSWORD.
        $driver = getenv('DB_DRIVER') ?: 'sqlite';

        $options = match ($driver) {
            'sqlite' => null,
            'mysql' => [
                'port' => '3306',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'strict' => true,
            ],
            'pgsql' => [
                'port' => '5432',
                'charset' => 'utf8',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ],
            default => throw new \RuntimeException(sprintf('DB_DRIVER is sqlite, mysql or pgsql, not %s.', $driver)),
        };

        if ($options !== null) {
            $app['config']->set('database.connections.testing', [
                ...$options,
                'driver' => $driver,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: $options['port'],
                'database' => getenv('DB_DATABASE') ?: 'testing',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: '',
                'prefix' => '',
            ]);
        }
    }

    /**
     * Skip the test when a binary it needs is missing. CI installs them.
     */
    protected function requiresBinary(string $binary): void
    {
        if ((new ExecutableFinder)->find($binary) === null) {
            $this->markTestSkipped(sprintf('%s is not installed.', $binary));
        }
    }

    protected function requiresImagick(): void
    {
        if (! extension_loaded('imagick')) {
            $this->markTestSkipped('The imagick extension is not installed.');
        }
    }

    /**
     * A disk that is not local, as an S3 disk would be, in memory.
     */
    protected function useMemoryDisk(string $name): void
    {
        Storage::extend('memory', function () {
            $adapter = new InMemoryFilesystemAdapter;

            return new FilesystemAdapter(new Filesystem($adapter), $adapter);
        });
        config(['filesystems.disks.'.$name => ['driver' => 'memory']]);
        Storage::forgetDisk($name);
    }

    /**
     * The migration of the application's tables that the tests use (users,
     * posts, pages).
     */
    protected static function applicationMigrations(): string
    {
        return __DIR__.'/Support/migrations';
    }

    /**
     * For a test that builds a schema of its own (the 1.x tables, a published
     * migration) instead of the package's: it starts from an empty database,
     * and the next DatabaseTestCase migrates again. In memory, each test has
     * its own database already; on MySQL, tables outlive the test.
     */
    protected function startFromAnEmptyDatabase(): void
    {
        Artisan::call('db:wipe');
        RefreshDatabaseState::$migrated = false;
    }

    /**
     * Path of a file in tests/fixtures.
     */
    protected static function fixturePath(string $path = ''): string
    {
        return __DIR__.'/fixtures'.($path !== '' ? '/'.$path : '');
    }
}
