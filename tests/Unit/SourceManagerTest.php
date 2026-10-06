<?php

namespace Folklore\Mediatheque\Tests\Unit;

use ErrorException;
use Folklore\Mediatheque\Contracts\Source\Source as SourceContract;
use Folklore\Mediatheque\Exception\InvalidSourceException;
use Folklore\Mediatheque\SourceManager;
use Folklore\Mediatheque\Sources\FilesystemSource;
use Folklore\Mediatheque\Sources\LocalSource;
use Folklore\Mediatheque\Tests\TestCase;
use InvalidArgumentException;
use Mockery;

class SourceManagerTest extends TestCase
{
    /**
     * Test get default source
     */
    public function test_get_default_source()
    {
        $sourceManager = new SourceManager(app(), app('files'));
        $source = $sourceManager->getDefaultSource();
        $this->assertEquals($source, config('mediatheque.source'));
    }

    /**
     * Test set default source
     */
    public function test_set_default_source()
    {
        $sourceManager = new SourceManager(app(), app('files'));
        $sourceManager->setDefaultSource('cloud');
        $source = $sourceManager->getDefaultSource();
        $this->assertEquals('cloud', $source);
    }

    /**
     * Test the local source
     */
    public function test_public_source()
    {
        $sourceManager = new SourceManager(app(), app('files'));
        $source = $sourceManager->source('public');
        $this->assertInstanceOf(LocalSource::class, $source);
    }

    /**
     * Test the cloud source
     */
    public function test_cloud_source()
    {
        $sourceManager = new SourceManager(app(), app('files'));
        $source = $sourceManager->source('cloud');
        $this->assertInstanceOf(FilesystemSource::class, $source);
    }

    public function test_unknown_source_throws()
    {
        $sourceManager = new SourceManager(app(), app('files'));

        $this->expectException(InvalidSourceException::class);
        $sourceManager->source('unknown');
    }

    public function test_invalid_source_exception_is_an_invalid_argument_exception()
    {
        $this->assertInstanceOf(InvalidArgumentException::class, new InvalidSourceException);
    }

    public function test_extend_with_a_closure()
    {
        config()->set('mediatheque.sources.custom', ['driver' => 'custom']);
        $source = Mockery::mock(SourceContract::class);

        $sourceManager = new SourceManager(app(), app('files'));
        $sourceManager->extend('custom', function ($app, $config) use ($source) {
            return $source;
        });

        $this->assertSame($source, $sourceManager->source('custom'));
    }

    public function test_local_source_has_no_dynamic_property()
    {
        set_error_handler(function ($severity, $message) {
            throw new ErrorException($message, 0, $severity);
        }, E_DEPRECATED);

        try {
            $source = new LocalSource(['path' => sys_get_temp_dir()], app('files'));
        } finally {
            restore_error_handler();
        }

        $this->assertEquals(sys_get_temp_dir().'/file.jpg', $source->getFullPath('file.jpg'));
    }
}
