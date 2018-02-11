<?php

use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Contracts\Models\Video;
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
     * Test the constructor
     *
     * @test
     */
    public function testConstruct()
    {
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
}
