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
            'hls' => [
                'job' => \Folklore\Mediatheque\Jobs\Video\HLS::class,
                'representations' => [
                    [
                        'max_width' => 360,
                        'max_height' => 360,
                        'bitrate' => 800,
                    ],
                    [
                        'max_width' => 720,
                        'max_height' => 720,
                        'bitrate' => 2000,
                    ],
                    [
                        'max_width' => 1080,
                        'max_height' => 1080,
                        'bitrate' => 4000,
                    ],
                ],
            ],
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
        $this->assertTrue($source->exists($basePath . '/index_360p.m3u8'));
        $this->assertTrue($source->exists($basePath . '/index_360p_0000.ts'));

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());

        $hlsFile->delete();
        $this->assertFalse($source->exists($basePath));
    }

    /**
     * Test video pipeline
     *
     * @test
     */
    public function testHLSVertical()
    {
        $pipeline = Pipeline::fromJobs([
            'hls' => [
                'job' => \Folklore\Mediatheque\Jobs\Video\HLS::class,
                'representations' => [
                    [
                        'max_width' => 360,
                        'max_height' => 360,
                        'bitrate' => 800,
                    ],
                    [
                        'max_width' => 720,
                        'max_height' => 720,
                        'bitrate' => 2000,
                    ],
                    [
                        'max_width' => 1080,
                        'max_height' => 1080,
                        'bitrate' => 4000,
                    ],
                ],
            ],
        ]);

        $filePath = public_path('test_vertical_hevc.mp4');
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
        $expectedSegments = [
            'index.m3u8',
            'index_640p.m3u8',
            'index_640p_0000.ts',
            'index_640p_0001.ts',
            'index_640p_0002.ts',
            'index_1280p.m3u8',
            'index_1280p_0000.ts',
            'index_1280p_0001.ts',
            'index_1280p_0002.ts',
            'index_1920p.m3u8',
            'index_1920p_0000.ts',
            'index_1920p_0001.ts',
            'index_1920p_0002.ts',
        ];
        foreach ($expectedSegments as $segment) {
            $this->assertTrue($source->exists($basePath . '/' . $segment));
        }

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());

        $hlsFile->delete();
        $this->assertFalse($source->exists($basePath));
    }
}
