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

        $this->sourceManager = new SourceManager(app());
    }

    /**
     * Test get default driver
     *
     * @test
     * @covers ::getDefaultDriver
     */
    public function testGetDefaultDriver()
    {
        $driver = $this->sourceManager->getDefaultDriver();
        $this->assertEquals($driver, config('mediatheque.source'));
    }

    /**
     * Test set default driver
     *
     * @test
     * @covers ::setDefaultDriver
     */
    public function testSetDefaultDriver()
    {
        $this->sourceManager->setDefaultDriver('cloud');
        $driver = $this->sourceManager->getDefaultDriver();
        $this->assertEquals('cloud', $driver);
    }

    /**
     * Test the local driver
     *
     * @test
     * @covers ::createLocalDriver
     */
    public function testPublicDriver()
    {
        $driver = $this->sourceManager->driver('public');
        $this->assertInstanceOf(\Folklore\Mediatheque\Sources\LocalSource::class, $driver);
    }

    /**
     * Test the cloud driver
     *
     * @test
     * @covers ::createFilesystemDriver
     */
    public function testCloudDriver()
    {
        $driver = $this->sourceManager->driver('cloud');
        $this->assertInstanceOf(\Folklore\Mediatheque\Sources\FilesystemSource::class, $driver);
    }
}
