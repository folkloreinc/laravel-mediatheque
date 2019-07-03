<?php

use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Contracts\Model\Video;
use Folklore\Mediatheque\Contracts\Model\Audio;
use Illuminate\Support\Facades\Storage;

class MediaTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        $definition = app('command.migrate')->getDefinition();
        if ($definition->hasOption('realpath')) {
            $this->loadMigrationsFrom([
                '--database' => 'testbench',
                '--realpath' => realpath(__DIR__.'/../../src/migrations'),
            ]);
        } else {
            $this->artisan('migrate', [
                '--database' => 'testbench',
            ]);
        }
    }

    public function tearDown()
    {
        $filesPath = public_path('files');
        if (app('files')->exists($filesPath)) {
            app('files')->deleteDirectory($filesPath);
        }

        parent::tearDown();
    }

    /**
     * Test video pipeline
     *
     * @test
     */
    public function testVideo()
    {
        $media = media(public_path('test.mp4'));
        $media->load('files', 'metadatas');
        $this->assertEquals($media->type, 'video');
        $this->assertArrayHasKey('duration', $media->metadata);
        $this->assertArrayHasKey('width', $media->metadata);
        $this->assertArrayHasKey('height', $media->metadata);
    }

    /**
     * Test audio pipeline
     *
     * @test
     */
    public function testAudio()
    {
        $media = media(public_path('test.wav'));
        $media->load('files', 'metadatas');
        $this->assertEquals($media->type, 'audio');
        $this->assertArrayHasKey('duration', $media->metadata);
    }

    /**
     * Test font pipeline
     *
     * @test
     */
    public function testFont()
    {
        $media = media(public_path('font.otf'));
        $media->load('files', 'metadatas');
        $this->assertEquals($media->type, 'font');
        $this->assertArrayHasKey('font_family_name', $media->metadata);
    }
}
