<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\SourceManager;
use Folklore\Mediatheque\Sources\FilesystemSource;
use Folklore\Mediatheque\Sources\LocalSource;
use Folklore\Mediatheque\Tests\TestCase;

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
}
