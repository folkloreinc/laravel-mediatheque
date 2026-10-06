<?php

namespace Folklore\Mediatheque\Tests\Unit;

use Folklore\Mediatheque\Tests\TestCase;
use Folklore\Mediatheque\SourceManager;

class SourceManagerTest extends TestCase
{
    /**
     * Test get default source
     */
    public function testGetDefaultSource()
    {
        $sourceManager =  new SourceManager(app(), app('files'));
        $source = $sourceManager->getDefaultSource();
        $this->assertEquals($source, config('mediatheque.source'));
    }

    /**
     * Test set default source
     */
    public function testSetDefaultSource()
    {
        $sourceManager =  new SourceManager(app(), app('files'));
        $sourceManager->setDefaultSource('cloud');
        $source = $sourceManager->getDefaultSource();
        $this->assertEquals('cloud', $source);
    }

    /**
     * Test the local source
     */
    public function testPublicSource()
    {
        $sourceManager =  new SourceManager(app(), app('files'));
        $source = $sourceManager->source('public');
        $this->assertInstanceOf(\Folklore\Mediatheque\Sources\LocalSource::class, $source);
    }

    /**
     * Test the cloud source
     */
    public function testCloudSource()
    {
        $sourceManager =  new SourceManager(app(), app('files'));
        $source = $sourceManager->source('cloud');
        $this->assertInstanceOf(\Folklore\Mediatheque\Sources\FilesystemSource::class, $source);
    }
}
