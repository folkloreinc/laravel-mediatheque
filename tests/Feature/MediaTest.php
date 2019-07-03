<?php

use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Contracts\Model\Video;
use Folklore\Mediatheque\Contracts\Model\Audio;
use Illuminate\Support\Facades\Storage;

class MediaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);
    }

    protected function tearDown()
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
}
