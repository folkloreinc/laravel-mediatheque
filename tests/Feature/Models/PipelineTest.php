<?php

namespace Folklore\Mediatheque\Tests\Feature\Models;

use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Models\Pipeline as PipelineModel;
use Folklore\Mediatheque\Models\PipelineJob as PipelineJobModel;
use Folklore\Mediatheque\Support\Pipeline;
use Folklore\Mediatheque\Tests\Support\Models\ScopedMedia;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;

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

    public function test_model_to_process_honors_the_morph_map()
    {
        Relation::morphMap(['media' => get_class(app(MediaContract::class))]);

        try {
            $pipeline = $this->makePipeline('morph_map');

            $this->assertEquals('media', $pipeline->pipelinable_type);
            $this->assertEquals(
                $pipeline->pipelinable_id,
                $pipeline->fresh()->getModelToProcess()->getKey()
            );
        } finally {
            Relation::morphMap([], false);
        }
    }

    public function test_model_to_process_ignores_global_scopes()
    {
        $media = new ScopedMedia;
        $media->withoutTypePipeline();
        $media->type = 'image';
        $media->name = 'hidden';
        $media->save();

        $pipeline = new PipelineModel;
        $pipeline->setDefinition(new Pipeline('scoped', ['auto_start' => false]));
        $media->pipelines()->save($pipeline);

        $this->assertNull(ScopedMedia::find($media->getKey()));
        $this->assertTrue($media->is($pipeline->fresh()->getModelToProcess()));
    }

    public function test_model_to_process_throws_when_the_model_is_gone()
    {
        $pipeline = $this->makePipeline('gone');
        $pipeline->getModelToProcess()->newQuery()->whereKey($pipeline->pipelinable_id)->delete();

        $this->expectException(ModelNotFoundException::class);
        $pipeline->fresh()->getModelToProcess();
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
