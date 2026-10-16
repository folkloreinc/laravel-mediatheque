<?php

namespace Folklore\Mediatheque\Tests\Feature\Testing;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Tests\Contracts\RecorderContractTests;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Http\UploadedFile;

class InMemoryRecorderTest extends TestCase
{
    use RecorderContractTests;

    protected function recorder(): Recorder
    {
        return Mediatheque::fake()->recorder();
    }

    protected function createRun(PipelineDefinition $pipeline): PipelineRun
    {
        config(['mediatheque.pipelines.'.$pipeline->name => $pipeline->toArray()]);
        $fake = Mediatheque::fake()->holdPipelines();

        $media = $fake->ingest(UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'), pipeline: $pipeline->name);

        $run = $fake->runs($media, $pipeline->name)->first();
        $this->assertNotNull($run);

        return $run;
    }

    protected function freshJob(PipelineRun $run, string $rendition): PipelineJob
    {
        $job = $run->job($rendition);
        $this->assertNotNull($job);

        return $job;
    }

    protected function freshRun(PipelineRun $run): PipelineRun
    {
        return $run;
    }

    protected function freshMedia(PipelineRun $run): Media
    {
        return $run->media();
    }
}
