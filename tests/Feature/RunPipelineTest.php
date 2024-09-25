<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Tests\TestCase;
use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Contracts\Models\Media;
use Illuminate\Support\Facades\Storage;

class RunPipelineTest extends TestCase
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
        $pipeline = Pipeline::fromJobs([
            'h264' => \Folklore\Mediatheque\Jobs\Video\H264::class,
            'webm' => \Folklore\Mediatheque\Jobs\Video\WebM::class,
            'thumbnails' => \Folklore\Mediatheque\Jobs\Video\Thumbnails::class,
        ]);

        $handles = ['original', 'h264', 'webm', 'thumbnails'];

        $filePath = public_path('test.mp4');
        $model = app(Media::class);
        $model->withoutTypePipeline();
        $model->setOriginalFile($filePath);
        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');
        $this->assertEquals(
            $handles,
            $model->files
                ->map(function ($file) {
                    return $file->getHandle();
                })
                ->toArray()
        );

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
        foreach ($handles as $handle) {
            $file = $model->getFile($handle);
            $this->assertTrue(file_exists(public_path('files/' . $file->path)));
        }
    }

    /**
     * Test animated gif pipeline
     *
     * @test
     */
    public function testAnimatedGif()
    {
        $this->app['mediatheque.types']->type('video')->set('animatedImage', true);

        $pipeline = Pipeline::fromJobs([
            'h264' => \Folklore\Mediatheque\Jobs\Video\H264::class,
            'webm' => \Folklore\Mediatheque\Jobs\Video\WebM::class,
            'thumbnails' => \Folklore\Mediatheque\Jobs\Video\Thumbnails::class,
        ]);

        $handles = ['original', 'h264', 'webm', 'thumbnails'];

        $filePath = public_path('animated.gif');
        $model = app(Media::class);
        $model->withoutTypePipeline();
        $model->setOriginalFile($filePath);
        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');
        $this->assertEquals(
            $handles,
            $model->files
                ->map(function ($file) {
                    return $file->getHandle();
                })
                ->toArray()
        );

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
        foreach ($handles as $handle) {
            $file = $model->getFile($handle);
            $this->assertTrue(file_exists(public_path('files/' . $file->path)));
        }
    }

    /**
     * Test video pipeline
     *
     * @test
     */
    public function testVideoResize()
    {
        $pipeline = Pipeline::fromJobs([
            'h264' => [
                'job' => \Folklore\Mediatheque\Jobs\Video\H264::class,
                'max_width' => 100,
                'max_height' => 100,
            ],
        ]);

        $handles = ['original', 'h264'];

        $filePath = public_path('test.mp4');
        $model = app(Media::class);
        $model->withoutTypePipeline();
        $model->setOriginalFile($filePath);
        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');
        $this->assertEquals(
            $handles,
            $model->files
                ->map(function ($file) {
                    return $file->getHandle();
                })
                ->toArray()
        );

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
        foreach ($handles as $handle) {
            $file = $model->getFile($handle);
            $this->assertTrue(file_exists(public_path('files/' . $file->path)));
        }
    }

    /**
     * Test video pipeline
     *
     * @test
     */
    public function testVideoResizeNoUpscale()
    {
        $pipeline = Pipeline::fromJobs([
            'h264' => [
                'job' => \Folklore\Mediatheque\Jobs\Video\H264::class,
                'max_width' => 600,
                'max_height' => 600,
            ],
        ]);

        $handles = ['original', 'h264'];

        $filePath = public_path('test.mp4');
        $model = app(Media::class);
        $model->withoutTypePipeline();
        $model->setOriginalFile($filePath);
        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');
        $this->assertEquals(
            $handles,
            $model->files
                ->map(function ($file) {
                    return $file->getHandle();
                })
                ->toArray()
        );

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
        foreach ($handles as $handle) {
            $file = $model->getFile($handle);
            $this->assertTrue(file_exists(public_path('files/' . $file->path)));
            $this->assertEquals($file->getMetadata('width')->getValue(), 320);
        }
    }

    /**
     * Test video pipeline
     *
     * @test
     */
    public function testVideoResizeUpscale()
    {
        $pipeline = Pipeline::fromJobs([
            'h264' => [
                'job' => \Folklore\Mediatheque\Jobs\Video\H264::class,
                'max_width' => 600,
                'max_height' => 600,
                'upscale' => true,
            ],
        ]);

        $handles = ['original', 'h264'];

        $filePath = public_path('test.mp4');
        $model = app(Media::class);
        $model->withoutTypePipeline();
        $model->setOriginalFile($filePath);
        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');
        $this->assertEquals(
            $handles,
            $model->files
                ->map(function ($file) {
                    return $file->getHandle();
                })
                ->toArray()
        );

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
        foreach ($handles as $handle) {
            $file = $model->getFile($handle);
            $this->assertTrue(file_exists(public_path('files/' . $file->path)));
            if ($handle !== 'original') {
                $this->assertEquals($file->getMetadata('width')->getValue(), 600);
            }
        }
    }

    /**
     * Test audio pipeline
     *
     * @test
     */
    public function testAudio()
    {
        $pipeline = Pipeline::fromJobs([
            'thumbnails' => \Folklore\Mediatheque\Jobs\Audio\Thumbnails::class,
        ]);

        $handles = ['original', 'thumbnails'];

        $filePath = public_path('test.wav');
        $model = app(Media::class);
        $model->withoutTypePipeline();
        $model->setOriginalFile($filePath);
        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');

        $this->assertEquals(
            $handles,
            $model->files
                ->map(function ($file) {
                    return $file->getHandle();
                })
                ->toArray()
        );
        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
        foreach ($handles as $handle) {
            $file = $model->getFile($handle);
            $this->assertTrue(file_exists(public_path('files/' . $file->path)));
        }
    }

    /**
     * Test video pipeline
     *
     * @test
     */
    public function testHLS()
    {
        $pipeline = Pipeline::fromJobs([
            'hls' => \Folklore\Mediatheque\Jobs\Video\HLS::class,
        ]);

        $filePath = public_path('test.mp4');
        $model = app(Media::class);
        $model->withoutTypePipeline();
        $model->setOriginalFile($filePath);

        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');

        $expectedHandles = ['original', 'hls'];
        $this->assertEquals(
            $expectedHandles,
            $model->files
                ->map(function ($file) {
                    return $file->getHandle();
                })
                ->toArray()
        );

        $hlsFile = $model->getFile('hls');
        $source = $hlsFile->getSource();
        $basePath = dirname($hlsFile->path);
        $this->assertTrue($source->exists($basePath . '/index.m3u8'));
        $this->assertTrue($source->exists($basePath . '/index_180p.m3u8'));
        $this->assertTrue($source->exists($basePath . '/index_180p_0000.ts'));

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
    }
}
