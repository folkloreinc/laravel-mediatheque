<?php

namespace Folklore\Mediatheque\Tests\Feature\Models;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Models\Pipeline as PipelineModel;
use Folklore\Mediatheque\Models\PipelineJob as PipelineJobModel;
use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Tests\TestCase;

class PipelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--database' => 'testbench']);
    }

    public function test_all_jobs_ended_ignores_jobs_of_other_pipelines()
    {
        $ended = $this->makePipeline('ended');
        $this->addJob($ended, ['ended' => true]);

        $pending = $this->makePipeline('pending');
        $this->addJob($pending);

        $this->assertTrue($ended->fresh()->allJobsEnded());
        $this->assertFalse($pending->fresh()->allJobsEnded());
    }

    public function test_all_jobs_ended_is_false_while_a_job_is_running()
    {
        $pipeline = $this->makePipeline('running');
        $this->addJob($pipeline, ['ended' => true]);
        $this->addJob($pipeline, ['started' => true]);

        $this->assertFalse($pipeline->fresh()->allJobsEnded());
    }

    public function test_all_jobs_ended_counts_failed_jobs_as_ended()
    {
        $pipeline = $this->makePipeline('failed');
        $this->addJob($pipeline, ['ended' => true]);
        $this->addJob($pipeline, ['failed' => true]);

        $this->assertTrue($pipeline->fresh()->allJobsEnded());
    }

    protected function makePipeline(string $name): PipelineModel
    {
        $media = app(MediaContract::class);
        $media->withoutTypePipeline();
        $media->type = 'image';
        $media->save();

        $pipeline = new PipelineModel;
        $pipeline->setDefinition(new Pipeline($name, ['auto_start' => false]));
        $media->pipelines()->save($pipeline);

        return $pipeline;
    }

    protected function addJob(PipelineModel $pipeline, array $state = []): void
    {
        $job = new PipelineJobModel;
        $job->setDefinition(['name' => 'job', 'job' => 'Job', 'from_file' => 'original']);
        $job->forceFill($state);
        $pipeline->addJob($job);
    }
}
