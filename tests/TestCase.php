<?php

namespace Folklore\Mediatheque\Tests;

use Folklore\Mediatheque\ServiceProvider;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Application;
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
     * Path of a file in tests/fixtures.
     */
    protected static function fixturePath(string $path = ''): string
    {
        return __DIR__.'/fixtures'.($path !== '' ? '/'.$path : '');
    }
}
