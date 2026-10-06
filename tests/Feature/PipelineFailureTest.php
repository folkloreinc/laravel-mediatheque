<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Exception;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Jobs\RunPipelineJob;
use Folklore\Mediatheque\Models\Pipeline as PipelineModel;
use Folklore\Mediatheque\Models\PipelineJob as PipelineJobModel;
use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Tests\Support\Jobs\NoOutputJob;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

class PipelineFailureTest extends TestCase
{
    protected $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);

        $this->media = app(MediaContract::class);
        $this->media->withoutTypePipeline();
        $this->media->setOriginalFile(public_path('image.jpg'));
    }

    public function test_a_failed_job_fails_its_pipeline()
    {
        $pipeline = $this->makePipeline();
        $job = $this->addJob($pipeline, 'resize', 'original', ['started' => true]);

        (new RunPipelineJob($job, $this->media))->failed(new Exception('Transcoding failed'));

        $job->refresh();
        $pipeline->refresh();
        $this->assertTrue($job->failed);
        $this->assertStringContainsString('Transcoding failed', $job->failed_exception);
        $this->assertTrue($pipeline->failed);
        $this->assertFalse($pipeline->started);
    }

    public function test_a_failed_job_fails_the_jobs_waiting_for_its_file()
    {
        $pipeline = $this->makePipeline();
        $job = $this->addJob($pipeline, 'resize', 'original', ['started' => true]);
        $dependent = $this->addJob($pipeline, 'crop', 'resize');
        $nested = $this->addJob($pipeline, 'thumbnail', 'crop');

        (new RunPipelineJob($job, $this->media))->failed(new Exception('Transcoding failed'));

        $this->assertTrue($dependent->refresh()->failed);
        $this->assertTrue($nested->refresh()->failed);
        $this->assertTrue($pipeline->refresh()->failed);
    }

    public function test_a_failed_job_waits_for_the_other_running_jobs()
    {
        $pipeline = $this->makePipeline();
        $job = $this->addJob($pipeline, 'resize', 'original', ['started' => true]);
        $this->addJob($pipeline, 'webm', 'original', ['started' => true]);

        (new RunPipelineJob($job, $this->media))->failed(new Exception('Transcoding failed'));

        $pipeline->refresh();
        $this->assertFalse($pipeline->failed);
        $this->assertFalse($pipeline->ended);
    }

    public function test_jobs_waiting_for_a_file_that_was_not_created_fail()
    {
        $pipeline = Pipeline::fromJobs([
            'empty' => NoOutputJob::class,
            'next' => [
                'job' => NoOutputJob::class,
                'from_file' => 'empty',
            ],
        ]);

        $pipelineModel = $this->media->runPipeline($pipeline)->fresh();

        $this->assertTrue($pipelineModel->getJob('empty')->ended);
        $this->assertTrue($pipelineModel->getJob('next')->failed);
        $this->assertTrue($pipelineModel->failed);
    }

    public function test_a_job_is_dispatched_once()
    {
        Queue::fake();

        $pipeline = $this->makePipeline();
        $job = $this->addJob($pipeline, 'resize', 'original');

        $job->run();
        $job->fresh()->run();
        $job->run();

        Queue::assertPushed(RunPipelineJob::class, 1);
        $this->assertTrue($job->fresh()->started);
    }

    public function test_job_timeout_and_tries_default_to_the_worker_settings()
    {
        $job = $this->addJob($this->makePipeline(), 'resize', 'original');

        $runJob = new RunPipelineJob($job, $this->media);

        $this->assertNull($runJob->timeout);
        $this->assertNull($runJob->tries);
        $this->assertTrue($runJob->failOnTimeout);
    }

    public function test_job_timeout_and_tries_come_from_the_config()
    {
        config()->set('mediatheque.jobs.timeout', '120');
        config()->set('mediatheque.jobs.tries', '2');
        $job = $this->addJob($this->makePipeline(), 'resize', 'original');

        $runJob = new RunPipelineJob($job, $this->media);

        $this->assertSame(120, $runJob->timeout);
        $this->assertSame(2, $runJob->tries);
    }

    public function test_a_job_definition_overrides_timeout_and_tries()
    {
        config()->set('mediatheque.jobs.timeout', 120);
        $job = $this->addJob($this->makePipeline(), 'resize', 'original', [], [
            'timeout' => 30,
            'tries' => 3,
        ]);

        $runJob = new RunPipelineJob($job, $this->media);

        $this->assertSame(30, $runJob->timeout);
        $this->assertSame(3, $runJob->tries);
    }

    protected function makePipeline(): PipelineModel
    {
        $pipeline = new PipelineModel;
        $pipeline->setDefinition(new Pipeline('test', ['auto_start' => false]));
        $pipeline->started = true;
        $this->media->pipelines()->save($pipeline);

        return $pipeline;
    }

    protected function addJob(
        PipelineModel $pipeline,
        string $name,
        string $fromFile,
        array $state = [],
        array $definition = []
    ): PipelineJobModel {
        $job = new PipelineJobModel;
        $job->setDefinition(array_merge([
            'name' => $name,
            'job' => NoOutputJob::class,
            'from_file' => $fromFile,
            'queue' => true,
        ], $definition));
        $job->forceFill($state);
        $pipeline->addJob($job);

        return $job;
    }
}
