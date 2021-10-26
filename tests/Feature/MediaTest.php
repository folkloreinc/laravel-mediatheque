<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Tests\TestCase;
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

    protected function tearDown(): void
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
        $metadatas = $media->getMetadatas()->toArray();
        $this->assertArrayHasKey('duration', $metadatas);
        $this->assertArrayHasKey('width', $metadatas);
        $this->assertArrayHasKey('height', $metadatas);
    }

    /**
     * Test video pipeline
     *
     * @test
     */
    public function testAnimatedGif()
    {
        $this->app['mediatheque.types']->type('video')->set('animatedImage', true);

        $media = media(public_path('animated.gif'));
        $media->load('files', 'metadatas');
        $this->assertEquals($media->type, 'video');
        $metadatas = $media->getMetadatas()->toArray();
        $this->assertArrayHasKey('duration', $metadatas);
        $this->assertArrayHasKey('width', $metadatas);
        $this->assertArrayHasKey('height', $metadatas);
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
        $metadatas = $media->getMetadatas()->toArray();
        $this->assertArrayHasKey('duration', $metadatas);
    }

    /**
     * Test image pipeline
     *
     * @test
     */
    public function testImage()
    {
        $media = media(public_path('image.jpg'));
        $media->load('files', 'metadatas');
        $this->assertEquals($media->type, 'image');
        $metadatas = $media->getMetadatas()->toArray();
        $this->assertArrayHasKey('width', $metadatas);
        $this->assertArrayHasKey('height', $metadatas);
    }
}
