<?php

use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Contracts\Models\Video;
use Folklore\Mediatheque\Contracts\Models\Audio;
use Illuminate\Support\Facades\Storage;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class RunPipelineTest extends TestCase
{
    use DatabaseMigrations;

    public function setUp()
    {
        parent::setUp();
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
        config()->set('mediatheque.types.video.pipeline', null);

        $pipeline = Pipeline::fromJobs([
            'h264' => \Folklore\Mediatheque\Jobs\Video\H264::class,
            'webm' => \Folklore\Mediatheque\Jobs\Video\WebM::class,
            'thumbnails' => \Folklore\Mediatheque\Jobs\Video\Thumbnails::class,
        ]);

        $filePath = public_path('test.mp4');
        $model = app(Video::class);
        $model->setOriginalFile($filePath);
        $model->runPipeline($pipeline);

        $model->load('files');

        $handles = $model->files->pluck('handle');
        $this->assertEquals([
            'original',
            'h264',
            'webm',
            'thumbnails'
        ], $handles->toArray());
    }

    /**
     * Test audio pipeline
     *
     * @test
     */
    public function testAudio()
    {
        config()->set('mediatheque.types.audio.pipeline', null);

        $pipeline = Pipeline::fromJobs([
            'thumbnails' => \Folklore\Mediatheque\Jobs\Audio\Thumbnails::class,
        ]);

        $filePath = public_path('test.wav');
        $model = app(Audio::class);
        $model->setOriginalFile($filePath);
        $model->runPipeline($pipeline);

        $model->load('files');

        $handles = $model->files->pluck('handle');
        $this->assertEquals([
            'original',
            'thumbnails'
        ], $handles->toArray());
    }
}
