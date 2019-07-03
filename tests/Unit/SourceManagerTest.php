<?php

use Folklore\Mediatheque\SourceManager;

/**
 * @coversDefaultClass Folklore\Mediatheque\SourceManager
 */
class SourceManagerTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->sourceManager = new SourceManager(app(), app('files'));
    }

    /**
     * Test get default source
     *
     * @test
     * @covers ::getDefaultSource
     */
    public function testGetDefaultSource()
    {
        $source = $this->sourceManager->getDefaultSource();
        $this->assertEquals($source, config('mediatheque.source'));
    }

    /**
     * Test set default source
     *
     * @test
     * @covers ::setDefaultSource
     */
    public function testSetDefaultSource()
    {
        $this->sourceManager->setDefaultSource('cloud');
        $source = $this->sourceManager->getDefaultSource();
        $this->assertEquals('cloud', $source);
    }

    /**
     * Test the local source
     *
     * @test
     * @covers ::createLocalSource
     */
    public function testPublicSource()
    {
        $source = $this->sourceManager->source('public');
        $this->assertInstanceOf(\Folklore\Mediatheque\Sources\LocalSource::class, $source);
    }

    /**
     * Test the cloud source
     *
     * @test
     * @covers ::createFilesystemSource
     */
    public function testCloudSource()
    {
        $source = $this->sourceManager->source('cloud');
        $this->assertInstanceOf(\Folklore\Mediatheque\Sources\FilesystemSource::class, $source);
    }
}
