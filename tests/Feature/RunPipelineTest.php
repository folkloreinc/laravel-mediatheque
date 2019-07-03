<?php

use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Contracts\Models\Media;
use Illuminate\Support\Facades\Storage;

class RunPipelineTest extends TestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);
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

        $handles = [
            'original',
            'h264',
            'webm',
            'thumbnails'
        ];

        $filePath = public_path('test.mp4');
        $model = app(Media::class);
        $model->setOriginalFile($filePath);
        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');
        $this->assertEquals($handles, $model->files->pluck('handle')->toArray());

        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
        foreach ($handles as $handle) {
            $file = $model->files->{$handle};
            $this->assertTrue(file_exists(public_path('files/'.$file->path)));
        }
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

        $handles = [
            'original',
            'thumbnails'
        ];

        $filePath = public_path('test.wav');
        $model = app(Media::class);
        $model->setOriginalFile($filePath);
        $pipelineModel = $model->runPipeline($pipeline);
        $pipelineModel = $pipelineModel->fresh();
        $model = $model->fresh();
        $model->load('files');

        $this->assertEquals($handles, $model->files->pluck('handle')->toArray());
        $this->assertTrue($pipelineModel->ended);
        $this->assertFalse($pipelineModel->started);
        $this->assertFalse($pipelineModel->failed);
        $this->assertTrue($pipelineModel->allJobsEnded());
        $this->assertFalse($pipelineModel->hasFailedJobs());
        foreach ($handles as $handle) {
            $file = $model->files->{$handle};
            $this->assertTrue(file_exists(public_path('files/'.$file->path)));
        }
    }
}
