<?php

namespace Folklore\Mediatheque\Tests\Feature\Pipelines;

use Carbon\CarbonInterface;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Jobs\DeleteStoredFiles;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob as PipelineJobModel;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\EloquentRecorder;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\PipelineRunner;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\Contracts\RecorderContractTests;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Transcoders\Capabilities;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;

class EloquentRecorderTest extends DatabaseTestCase
{
    use CreatesMedia;
    use RecorderContractTests;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([DeleteStoredFiles::class]);

        // A driver that supports every operation, so runs are created as the contract tests define them
        app(TranscoderManager::class)->extend('recorder-test', fn () => new class implements Transcoder
        {
            public function supports(string $operation): bool
            {
                return true;
            }

            public function capabilities(): Capabilities
            {
                return Capabilities::none();
            }

            public function submit(PipelineRunContract $run): ?string
            {
                return null;
            }

            public function cancel(PipelineRunContract $run): void {}

            public function refresh(PipelineRunContract $run): void {}
        });
    }

    protected function recorder(): Recorder
    {
        return app(Recorder::class);
    }

    protected function createRun(PipelineDefinition $pipeline): PipelineRunContract
    {
        $pipeline = new PipelineDefinition($pipeline->name, $pipeline->renditions, 'recorder-test', $pipeline->source);
        config(['mediatheque.pipelines.'.$pipeline->name => $pipeline->toArray()]);
        $media = $this->createMedia(['type' => 'video', 'status' => MediaStatus::Processing]);
        $this->createFile($media, 'original', ['mime' => 'video/mp4']);

        return app(PipelineRunner::class)->create($media, $pipeline, array_keys($pipeline->renditions));
    }

    protected function freshJob(PipelineRunContract $run, string $rendition): PipelineJob
    {
        $job = $this->freshRun($run)->job($rendition);
        $this->assertNotNull($job);

        return $job;
    }

    protected function freshRun(PipelineRunContract $run): PipelineRun
    {
        return PipelineRun::query()->where('uid', $run->key())->firstOrFail();
    }

    protected function freshMedia(PipelineRunContract $run): MediaContract
    {
        return Media::query()->where('uid', $run->media()->uid())->firstOrFail();
    }

    protected function rerun(PipelineRunContract $run, array $renditions): PipelineRunContract
    {
        $media = $this->freshMedia($run);
        $this->assertInstanceOf(Media::class, $media);

        return app(PipelineRunner::class)->create($media, PipelineDefinition::fromArray($run->name(), (array) config('mediatheque.pipelines.'.$run->name())), $renditions);
    }

    #[Test]
    public function the_package_binds_the_eloquent_recorder(): void
    {
        $this->assertInstanceOf(EloquentRecorder::class, $this->recorder());
    }

    #[Test]
    public function files_get_their_size_and_checksum_from_their_disk_when_the_driver_reports_none(): void
    {
        $run = $this->createRun($this->videoPipeline());
        Storage::disk('public')->put($run->pathPrefix().'/poster.jpg', 'poster');

        $this->recorder()->completed($this->freshJob($run, 'poster'), Outputs::file(new OutputFile($run->pathPrefix().'/poster.jpg', mime: 'image/jpeg')));

        $poster = $this->freshMedia($run)->file('poster');
        $this->assertSame([6, hash('sha256', 'poster')], [$poster?->size(), $poster?->checksum()]);
    }

    #[Test]
    public function a_rerun_replaces_the_files_of_a_rendition_and_deletes_the_old_ones_in_the_queue(): void
    {
        $run = $this->createRun($this->videoPipeline());
        $this->recorder()->completed($this->freshJob($run, 'poster'), Outputs::file(new OutputFile($run->pathPrefix().'/poster.jpg', mime: 'image/jpeg', size: 1)));

        $rerun = $this->rerun($run, ['poster']);
        $this->recorder()->completed($this->freshJob($rerun, 'poster'), Outputs::file(new OutputFile($rerun->pathPrefix().'/poster.jpg', mime: 'image/jpeg', size: 1)));

        $media = $this->freshMedia($run);
        $this->assertCount(1, $media->files('poster'));
        $this->assertSame($rerun->pathPrefix().'/poster.jpg', $media->file('poster')?->path());
        Bus::assertDispatched(DeleteStoredFiles::class, fn (DeleteStoredFiles $job) => $job->disk === 'public' && $job->paths === [$run->pathPrefix().'/poster.jpg']);
    }

    #[Test]
    public function a_run_records_its_output_source_prefix_visibility_and_main_rendition(): void
    {
        $run = $this->createRun($this->videoPipeline());

        $this->assertSame('public', $run->outputSource());
        $this->assertSame('video/'.$run->media()->uid().'/'.$run->key(), $run->pathPrefix());
        $this->assertSame($run->media()->visibility(), $run->visibility());
        $this->assertInstanceOf(PipelineRun::class, $run);
        $this->assertSame('h264', $run->mainRendition());
    }

    #[Test]
    public function a_rendition_waits_in_the_queue_from_its_run_or_from_when_its_input_completes(): void
    {
        $this->travelTo('2026-10-21 10:00:00');
        $run = $this->createRun($this->videoPipeline());
        $queuedAt = fn (string $rendition) => $this->queuedAt($run, $rendition)?->toDateTimeString();

        $this->assertSame(['2026-10-21 10:00:00', '2026-10-21 10:00:00', null, null], [$queuedAt('normalized'), $queuedAt('poster'), $queuedAt('h264'), $queuedAt('webm')]);

        $this->travelTo('2026-10-21 10:05:00');
        $this->recorder()->completed($this->freshJob($run, 'normalized'), Outputs::none());

        $this->assertSame(['2026-10-21 10:05:00', null], [$queuedAt('h264'), $queuedAt('webm')]);
        $this->assertSame('2026-10-21 10:00:00', $queuedAt('poster'));
    }

    #[Test]
    public function a_rendition_whose_input_failed_never_waits_in_the_queue(): void
    {
        $run = $this->createRun($this->videoPipeline());

        $this->recorder()->failed($this->freshJob($run, 'normalized'), new TranscoderError(TranscoderErrorCode::OperationFailed, 'Failed'));

        $this->assertNull($this->queuedAt($run, 'h264'));
    }

    private function queuedAt(PipelineRunContract $run, string $rendition): ?CarbonInterface
    {
        return PipelineJobModel::query()->where('pipeline_id', $this->freshRun($run)->id)->where('name', $rendition)->sole()->queued_at;
    }
}
