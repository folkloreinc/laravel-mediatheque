<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Events\MediaReady;
use Folklore\Mediatheque\Events\PipelineCanceled;
use Folklore\Mediatheque\Events\PipelineCompleted;
use Folklore\Mediatheque\Events\PipelineFailed;
use Folklore\Mediatheque\Events\PipelineStarted;
use Folklore\Mediatheque\Events\RenditionCompleted;
use Folklore\Mediatheque\Events\RenditionFailed;
use Folklore\Mediatheque\Events\RenditionSkipped;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * The rules of the Recorder contract (docs/decisions/0011, 0012). Every
 * recorder runs these tests: the fake's in-memory recorder now, the
 * package's recorder when it lands.
 */
trait RecorderContractTests
{
    abstract protected function recorder(): Recorder;

    /**
     * A probed media with an original, `processing`, whose pipeline is this
     * one, and a run of it with every job queued and nothing reported yet.
     */
    abstract protected function createRun(PipelineDefinition $pipeline): PipelineRun;

    /**
     * The job as it is stored now.
     */
    abstract protected function freshJob(PipelineRun $run, string $rendition): PipelineJob;

    abstract protected function freshRun(PipelineRun $run): PipelineRun;

    abstract protected function freshMedia(PipelineRun $run): Media;

    /**
     * Another run of the same pipeline on the same media, with these
     * renditions (closed under `from`), every job queued.
     *
     * @param  list<string>  $renditions
     */
    abstract protected function rerun(PipelineRun $run, array $renditions): PipelineRun;

    protected function videoPipeline(): PipelineDefinition
    {
        return PipelineDefinition::fromArray('video', ['renditions' => [
            'normalized' => ['op' => 'video.transcode', 'keep' => false],
            'h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'from' => 'normalized'],
            'webm' => ['op' => 'video.transcode', 'codec' => 'vp9', 'from' => 'h264'],
            'poster' => ['op' => 'video.poster', 'role' => 'poster'],
            'thumbnails' => ['op' => 'video.thumbnails', 'count' => 3, 'role' => 'thumbnail', 'from' => 'h264'],
            'palette' => ['op' => 'image.palette', 'role' => 'metadata'],
        ]]);
    }

    private function job(PipelineRun $run, string $rendition): PipelineJob
    {
        return $this->freshJob($run, $rendition);
    }

    private function file(PipelineRun $run, string $name, ?int $index = null): OutputFile
    {
        return new OutputFile($run->pathPrefix().'/'.$name.($index !== null ? '-'.$index : '').'.bin', index: $index, mime: 'application/octet-stream', size: 3);
    }

    private function complete(PipelineRun $run, string $rendition): void
    {
        $outputs = match ($rendition) {
            'thumbnails' => Outputs::group([$this->file($run, 'thumbnails', 0), $this->file($run, 'thumbnails', 1), $this->file($run, 'thumbnails', 2)]),
            'palette', 'normalized' => Outputs::none(),
            default => Outputs::file($this->file($run, $rendition)),
        };

        $this->recorder()->completed($this->job($run, $rendition), $outputs);
    }

    #[Test]
    public function started_moves_the_job_and_its_run_to_running_once(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->recorder()->started($this->job($run, 'normalized'));
        $this->recorder()->started($this->job($run, 'normalized'));
        $this->recorder()->started($this->job($run, 'poster'));

        $this->assertSame(JobStatus::Running, $this->job($run, 'normalized')->status());
        $this->assertSame(PipelineStatus::Running, $this->freshRun($run)->status());
        Event::assertDispatchedTimes(PipelineStarted::class, 1);
    }

    #[Test]
    public function completed_attaches_files_under_the_rendition_handle(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->recorder()->completed($this->job($run, 'h264'), Outputs::file(new OutputFile(
            $run->pathPrefix().'/web.mp4',
            mime: 'video/mp4',
            size: 1024,
            width: 1280,
            height: 720,
            duration: 10.0,
            bitrate: 2_000_000,
        )));

        $file = $this->freshMedia($run)->file('h264');
        $this->assertNotNull($file);
        $this->assertSame($run->pathPrefix().'/web.mp4', $file->path(), 'The file records the path the driver reports.');
        $this->assertSame($run->outputSource(), $file->source());
        $this->assertSame('main', $file->role());
        $this->assertNull($file->index());
        $this->assertSame([1280, 720, 10.0, 2_000_000], [$file->width(), $file->height(), $file->duration(), $file->bitrate()]);
        $this->assertSame(JobStatus::Completed, $this->job($run, 'h264')->status());
        $this->assertSame(1.0, $this->job($run, 'h264')->progress());
        Event::assertDispatched(RenditionCompleted::class, fn (RenditionCompleted $event) => $event->rendition() === 'h264');
    }

    #[Test]
    public function a_group_is_attached_with_its_indexes(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->complete($run, 'thumbnails');

        $media = $this->freshMedia($run);
        $this->assertSame([0, 1, 2], $media->files('thumbnails')->map(fn ($file) => $file->index())->all());
        $this->assertSame($run->pathPrefix().'/thumbnails-2.bin', $media->file('thumbnails', 2)?->path());
        $this->assertSame('thumbnail', $media->file('thumbnails', 1)?->role());
        $this->assertSame($media->file('thumbnails', 0)?->path(), $media->thumbnail()?->path());
    }

    #[Test]
    public function metadata_outputs_go_to_the_media_without_files_and_to_the_main_file_with_files(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->recorder()->completed($this->job($run, 'palette'), Outputs::none()->withMetadata(['palette' => ['#102030', '#405060']]));
        $this->recorder()->completed($this->job($run, 'thumbnails'), Outputs::group([
            $this->file($run, 'thumbnails', 0),
            $this->file($run, 'thumbnails', 1),
        ])->withMetadata(['scenes' => [0.0, 4.2]]));

        $media = $this->freshMedia($run);
        $this->assertSame(['#102030', '#405060'], $media->metadata('palette'));
        $this->assertSame([0.0, 4.2], $media->file('thumbnails', 0)?->metadata('scenes'));
        $this->assertNull($media->file('thumbnails', 1)?->metadata('scenes'));
        $this->assertNull($media->metadata('scenes'));
    }

    #[Test]
    public function a_rendition_that_is_not_kept_completes_without_a_file(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->recorder()->completed($this->job($run, 'normalized'), Outputs::file($this->file($run, 'normalized')));

        $this->assertSame(JobStatus::Completed, $this->job($run, 'normalized')->status());
        $this->assertNull($this->freshMedia($run)->file('normalized'));
    }

    #[Test]
    public function a_repeated_call_changes_nothing(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->recorder()->completed($this->job($run, 'h264'), Outputs::file($this->file($run, 'h264')));
        $this->recorder()->completed($this->job($run, 'h264'), Outputs::file(new OutputFile($run->pathPrefix().'/other.mp4')));

        $media = $this->freshMedia($run);
        $this->assertCount(1, $media->files('h264'));
        $this->assertSame($run->pathPrefix().'/h264.bin', $media->file('h264')?->path());
        Event::assertDispatchedTimes(RenditionCompleted::class, 1);
    }

    #[Test]
    public function a_final_status_never_changes(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());
        $recorder = $this->recorder();

        $this->complete($run, 'poster');
        $recorder->started($this->job($run, 'poster'));
        $recorder->progress($this->job($run, 'poster'), 0.2);
        $recorder->failed($this->job($run, 'poster'), new TranscoderError(TranscoderErrorCode::Timeout, 'Late webhook'));
        $recorder->skipped($this->job($run, 'poster'), 'Late webhook');
        $recorder->canceled($this->job($run, 'poster'));

        $job = $this->job($run, 'poster');
        $this->assertSame(JobStatus::Completed, $job->status());
        $this->assertSame(1.0, $job->progress());
        $this->assertNull($job->error());
        $this->assertNotNull($this->freshMedia($run)->file('poster'));
        Event::assertNotDispatched(RenditionFailed::class);
        Event::assertNotDispatched(RenditionSkipped::class);
    }

    #[Test]
    public function a_final_status_can_arrive_before_running(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->complete($run, 'poster');

        $this->assertSame(JobStatus::Completed, $this->job($run, 'poster')->status());
        $this->assertSame(PipelineStatus::Running, $this->freshRun($run)->status());
        Event::assertDispatchedTimes(PipelineStarted::class, 1);
    }

    #[Test]
    public function progress_never_goes_down(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());
        $recorder = $this->recorder();

        $recorder->progress($this->job($run, 'normalized'), 0.5);
        $this->assertSame(JobStatus::Running, $this->job($run, 'normalized')->status(), 'Progress means the job runs.');

        $recorder->progress($this->job($run, 'normalized'), 0.3);
        $this->assertSame(0.5, $this->job($run, 'normalized')->progress());

        $recorder->progress($this->job($run, 'normalized'), 7.0);
        $this->assertSame(1.0, $this->job($run, 'normalized')->progress());
    }

    #[Test]
    public function a_failure_fails_the_renditions_that_read_from_it(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->recorder()->failed($this->job($run, 'normalized'), new TranscoderError(TranscoderErrorCode::UnsupportedInput, 'Unknown codec'));

        $this->assertSame(TranscoderErrorCode::UnsupportedInput, $this->job($run, 'normalized')->error()?->code);
        foreach (['h264', 'webm', 'thumbnails'] as $dependent) {
            $this->assertSame(JobStatus::Failed, $this->job($run, $dependent)->status(), $dependent);
            $this->assertSame(TranscoderErrorCode::DependencyFailed, $this->job($run, $dependent)->error()?->code, $dependent);
        }
        $this->assertSame(JobStatus::Queued, $this->job($run, 'poster')->status(), 'Independent renditions continue.');
        $this->assertSame(PipelineStatus::Running, $this->freshRun($run)->status());
        Event::assertDispatchedTimes(RenditionFailed::class, 4);
    }

    #[Test]
    public function a_skip_skips_the_renditions_that_read_from_it(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->complete($run, 'normalized');
        $this->recorder()->skipped($this->job($run, 'h264'), 'Audio only');

        $this->assertSame('Audio only', $this->job($run, 'h264')->reason());
        foreach (['webm', 'thumbnails'] as $dependent) {
            $this->assertSame(JobStatus::Skipped, $this->job($run, $dependent)->status(), $dependent);
            $this->assertStringContainsString('"h264"', (string) $this->job($run, $dependent)->reason());
        }
        $this->assertSame(JobStatus::Queued, $this->job($run, 'poster')->status());
        Event::assertDispatchedTimes(RenditionSkipped::class, 3);
        Event::assertNotDispatched(RenditionFailed::class);
    }

    #[Test]
    public function the_run_completes_when_every_rendition_ended_and_the_media_is_ready(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        foreach (['normalized', 'h264', 'webm', 'poster', 'thumbnails'] as $rendition) {
            $this->complete($run, $rendition);
            $this->assertSame(PipelineStatus::Running, $this->freshRun($run)->status());
        }
        $this->recorder()->skipped($this->job($run, 'palette'), 'Not needed');

        $this->assertSame(PipelineStatus::Completed, $this->freshRun($run)->status());
        $this->assertSame(MediaStatus::Ready, $this->freshMedia($run)->status());
        Event::assertDispatchedTimes(PipelineCompleted::class, 1);
        Event::assertDispatchedTimes(MediaReady::class, 1);
        Event::assertNotDispatched(PipelineFailed::class);
    }

    #[Test]
    public function the_run_fails_if_a_rendition_failed_and_the_media_is_ready_if_its_main_one_completed(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        foreach (['normalized', 'h264', 'webm', 'thumbnails', 'palette'] as $rendition) {
            $this->complete($run, $rendition);
        }
        $this->recorder()->failed($this->job($run, 'poster'), new TranscoderError(TranscoderErrorCode::Timeout, 'Too slow'));

        $this->assertSame(PipelineStatus::Failed, $this->freshRun($run)->status());
        $this->assertSame(MediaStatus::Ready, $this->freshMedia($run)->status());
        Event::assertDispatchedTimes(PipelineFailed::class, 1);
        Event::assertDispatchedTimes(MediaReady::class, 1);
    }

    #[Test]
    public function the_media_fails_when_its_main_rendition_did_not_complete(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        $this->complete($run, 'poster');
        $this->complete($run, 'palette');
        $this->recorder()->failed($this->job($run, 'normalized'), new TranscoderError(TranscoderErrorCode::InputUnreadable, 'Corrupted'));

        $this->assertSame(PipelineStatus::Failed, $this->freshRun($run)->status());
        $this->assertSame(MediaStatus::Failed, $this->freshMedia($run)->status());
        Event::assertDispatched(MediaFailed::class, fn (MediaFailed $event) => $event->error?->code === TranscoderErrorCode::DependencyFailed);
        Event::assertNotDispatched(MediaReady::class);
    }

    #[Test]
    public function the_run_is_canceled_if_a_rendition_was_canceled_and_none_failed(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());

        foreach (['normalized', 'h264', 'webm', 'thumbnails', 'palette'] as $rendition) {
            $this->complete($run, $rendition);
        }
        $this->recorder()->canceled($this->job($run, 'poster'));

        $this->assertSame(PipelineStatus::Canceled, $this->freshRun($run)->status());
        Event::assertDispatchedTimes(PipelineCanceled::class, 1);
    }

    #[Test]
    public function a_late_callback_for_an_ended_run_changes_nothing(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());
        foreach (['normalized', 'h264', 'webm', 'poster', 'thumbnails', 'palette'] as $rendition) {
            $this->complete($run, $rendition);
        }

        $this->recorder()->failed($this->job($run, 'h264'), new TranscoderError(TranscoderErrorCode::ProviderFailed, 'Late'));
        $this->recorder()->canceled($this->job($run, 'webm'));

        $this->assertSame(PipelineStatus::Completed, $this->freshRun($run)->status());
        $this->assertSame(MediaStatus::Ready, $this->freshMedia($run)->status());
        Event::assertDispatchedTimes(PipelineCompleted::class, 1);
        Event::assertNotDispatched(PipelineFailed::class);
        Event::assertNotDispatched(RenditionFailed::class);
    }

    #[Test]
    public function a_ready_media_stays_ready_when_a_rerun_of_its_main_rendition_fails(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());
        foreach (['normalized', 'h264', 'webm', 'poster', 'thumbnails', 'palette'] as $rendition) {
            $this->complete($run, $rendition);
        }

        $rerun = $this->rerun($run, ['h264']);
        $this->recorder()->failed($this->job($rerun, 'normalized'), new TranscoderError(TranscoderErrorCode::Timeout, 'Too slow'));

        $this->assertSame(PipelineStatus::Failed, $this->freshRun($rerun)->status());
        $this->assertSame(MediaStatus::Ready, $this->freshMedia($rerun)->status(), 'Its main rendition still has a file (docs/decisions/0014).');
        $this->assertSame($run->pathPrefix().'/h264.bin', $this->freshMedia($rerun)->file('h264')?->path());
        Event::assertNotDispatched(MediaFailed::class);
        Event::assertDispatchedTimes(PipelineFailed::class, 1);
    }

    #[Test]
    public function a_run_without_the_main_rendition_leaves_the_media_status_as_it_is(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());
        $this->recorder()->failed($this->job($run, 'normalized'), new TranscoderError(TranscoderErrorCode::InputUnreadable, 'Corrupted'));
        $this->recorder()->failed($this->job($run, 'poster'), new TranscoderError(TranscoderErrorCode::InputUnreadable, 'Corrupted'));
        $this->recorder()->failed($this->job($run, 'palette'), new TranscoderError(TranscoderErrorCode::InputUnreadable, 'Corrupted'));
        $this->assertSame(MediaStatus::Failed, $this->freshMedia($run)->status());

        $rerun = $this->rerun($run, ['poster']);
        $this->complete($rerun, 'poster');

        $this->assertSame(PipelineStatus::Completed, $this->freshRun($rerun)->status());
        $this->assertSame(MediaStatus::Failed, $this->freshMedia($rerun)->status());
        Event::assertNotDispatched(MediaReady::class);
    }

    #[Test]
    public function a_failed_media_is_ready_when_a_rerun_completes_its_main_rendition(): void
    {
        Event::fake();
        $run = $this->createRun($this->videoPipeline());
        $this->recorder()->failed($this->job($run, 'normalized'), new TranscoderError(TranscoderErrorCode::Timeout, 'Too slow'));
        $this->complete($run, 'poster');
        $this->complete($run, 'palette');
        $this->assertSame(MediaStatus::Failed, $this->freshMedia($run)->status());

        $rerun = $this->rerun($run, ['h264']);
        $this->complete($rerun, 'normalized');
        $this->complete($rerun, 'h264');

        $this->assertSame(MediaStatus::Ready, $this->freshMedia($rerun)->status());
        Event::assertDispatchedTimes(MediaReady::class, 1);
    }
}
