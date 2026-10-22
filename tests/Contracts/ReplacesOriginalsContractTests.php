<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Events\IngestRejected;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Events\OriginalReplaced;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mediatheque::replaceOriginal() (docs/decisions/0019), on a probed video
 * and the default `video` pipeline: `h264`, `poster` and `thumbnails`.
 */
trait ReplacesOriginalsContractTests
{
    abstract protected function mediatheque(): Mediatheque;

    /**
     * A probed video, `ready` or `processing`.
     */
    abstract protected function probedVideo(): Media;

    /**
     * A video to replace an original with, whose content is one per
     * `$variant`: the same variant is the same file.
     */
    abstract protected function videoFile(string $variant): UploadedFile|Source|string;

    abstract protected function imageFile(): UploadedFile|Source|string;

    /**
     * A file of no type.
     */
    abstract protected function unknownFile(): UploadedFile|Source|string;

    /**
     * Renditions of the runs created from now on stay queued, as if no
     * worker ran them.
     */
    abstract protected function holdRuns(): void;

    /**
     * Probes from now on complete, as a video.
     */
    abstract protected function probesSucceed(): void;

    abstract protected function freshRun(PipelineRun $run): PipelineRun;

    abstract protected function freshMedia(Media $media): Media;

    /**
     * The runs of a pipeline on a media, oldest first.
     *
     * @return list<PipelineRun>
     */
    abstract protected function runsOf(Media $media, string $pipeline): array;

    #[Test]
    public function the_media_keeps_its_uid_name_and_visibility_and_gets_the_new_original(): void
    {
        $this->probesSucceed();
        $media = $this->probedVideo();
        [$uid, $name, $visibility] = [$media->uid(), $media->name(), $media->visibility()];
        $previous = $media->original()?->checksum();

        $replaced = $this->mediatheque()->replaceOriginal($media, $this->videoFile('new cut'));

        $replaced = $this->freshMedia($replaced);
        $original = $replaced->original();
        $this->assertNotNull($original);
        $this->assertSame([$uid, $name, $visibility, 'video'], [$replaced->uid(), $replaced->name(), $replaced->visibility(), $replaced->type()]);
        $this->assertNotNull($original->checksum());
        $this->assertNotSame($previous, $original->checksum());
        $this->assertSame('video/mp4', $original->mime());
        $this->assertSame($original->checksum(), $replaced->metadata('checksum'));
        $this->assertSame($original->size(), $replaced->metadata('size'));
        $this->assertNotSame(MediaStatus::Failed, $replaced->status());
        $this->assertSame($uid, $this->mediatheque()->resolve('media://'.$uid)?->uid(), 'Its references keep resolving.');
    }

    #[Test]
    public function a_name_given_in_the_options_renames_the_media(): void
    {
        $this->probesSucceed();
        $media = $this->probedVideo();

        $replaced = $this->mediatheque()->replaceOriginal($media, $this->videoFile('new cut'), new IngestOptions(name: 'Episode 1, new cut'));

        $this->assertSame('Episode 1, new cut', $this->freshMedia($replaced)->name());
    }

    #[Test]
    public function a_file_ingest_would_refuse_is_refused_and_nothing_changes(): void
    {
        $media = $this->probedVideo();
        $original = $media->original();
        $runs = count($this->runsOf($media, 'video'));
        $rejected = [];
        Event::listen(IngestRejected::class, function (IngestRejected $event) use (&$rejected) {
            $rejected[] = $event;
        });

        try {
            $this->mediatheque()->replaceOriginal($media, $this->unknownFile());
            $this->fail('The file was not refused.');
        } catch (FileRejected $exception) {
            $this->assertSame(RejectionReason::UnknownType, $exception->reason);
        }

        $media = $this->freshMedia($media);
        $this->assertSame([$original?->path(), $original?->checksum()], [$media->original()?->path(), $media->original()?->checksum()]);
        $this->assertNotSame(MediaStatus::Pending, $media->status());
        $this->assertCount($runs, $this->runsOf($media, 'video'));
        $this->assertCount(1, $rejected);
    }

    #[Test]
    public function a_file_of_another_type_is_refused_unless_the_type_may_change(): void
    {
        $this->probesSucceed();
        $media = $this->probedVideo();
        $original = $media->original();

        try {
            $this->mediatheque()->replaceOriginal($media, $this->imageFile());
            $this->fail('The type changed.');
        } catch (FileRejected $exception) {
            $this->assertSame(RejectionReason::TypeChanged, $exception->reason);
        }

        $this->assertSame(['video', $original?->path()], [$this->freshMedia($media)->type(), $this->freshMedia($media)->original()?->path()]);

        $replaced = $this->freshMedia($this->mediatheque()->replaceOriginal($media, $this->imageFile(), allowTypeChange: true));

        $this->assertSame(['image', 'image/jpeg', $media->uid()], [$replaced->type(), $replaced->original()?->mime(), $replaced->uid()]);
    }

    #[Test]
    public function the_renditions_are_removed_and_produced_again_from_the_new_original(): void
    {
        $this->probesSucceed();
        $media = $this->probedVideo();
        $this->mediatheque()->runPipeline($media, 'video', force: true);
        $before = $this->freshMedia($media)->file('h264')?->path();
        $this->assertNotNull($before);
        $runs = count($this->runsOf($media, 'video'));

        $this->mediatheque()->replaceOriginal($media, $this->videoFile('new cut'));

        $media = $this->freshMedia($media);
        $this->assertCount($runs + 1, $this->runsOf($media, 'video'), 'The pipeline ran again, once.');
        $after = $media->file('h264')?->path();
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after);
        $this->assertSame(MediaStatus::Ready, $media->status());
    }

    #[Test]
    public function a_run_in_progress_is_canceled_and_its_late_results_are_ignored(): void
    {
        $this->probesSucceed();
        $this->holdRuns();
        $media = $this->probedVideo();
        $run = $this->mediatheque()->runPipeline($media, 'video', force: true);
        $this->assertNotNull($run);
        $failed = [];
        Event::listen(MediaFailed::class, function (MediaFailed $event) use (&$failed) {
            $failed[] = $event;
        });

        $this->mediatheque()->replaceOriginal($media, $this->videoFile('new cut'));

        $run = $this->freshRun($run);
        $this->assertSame(PipelineStatus::Canceled, $run->status());
        $this->assertSame([JobStatus::Canceled], $run->jobs()->map(fn ($job) => $job->status())->unique()->values()->all());
        $this->assertSame([], $failed, 'The media is replaced, not failed.');
        $this->assertNotSame(MediaStatus::Failed, $this->freshMedia($media)->status());

        $job = $run->job('h264');
        $this->assertNotNull($job);
        app(Recorder::class)->completed($job, Outputs::file(new OutputFile('late/h264.mp4', mime: 'video/mp4', size: 4, checksum: hash('sha256', 'late'))));

        $this->assertSame(JobStatus::Canceled, $this->freshRun($run)->job('h264')?->status());
        $this->assertNotSame('late/h264.mp4', $this->freshMedia($media)->file('h264')?->path());
    }

    #[Test]
    public function original_replaced_is_dispatched_with_the_previous_and_the_new_checksum(): void
    {
        $this->probesSucceed();
        $media = $this->probedVideo();
        $this->mediatheque()->replaceOriginal($media, $this->videoFile('first cut'));
        $previous = $this->freshMedia($media)->original()?->checksum();
        $events = [];
        Event::listen(OriginalReplaced::class, function (OriginalReplaced $event) use (&$events) {
            $events[] = $event;
        });

        $this->mediatheque()->replaceOriginal($media, $this->videoFile('second cut'));

        $this->assertCount(1, $events);
        $this->assertSame($media->uid(), $events[0]->media->uid());
        $this->assertNotNull($previous);
        $this->assertSame($previous, $events[0]->previousChecksum);
        $this->assertSame($this->freshMedia($media)->original()?->checksum(), $events[0]->checksum);
        $this->assertNotSame($previous, $events[0]->checksum);
    }

    #[Test]
    public function replacing_with_the_same_file_again_changes_nothing(): void
    {
        $this->probesSucceed();
        $media = $this->probedVideo();
        $this->mediatheque()->replaceOriginal($media, $this->videoFile('new cut'));
        $original = $this->freshMedia($media)->original();
        $runs = count($this->runsOf($media, 'video'));
        $events = [];
        Event::listen(OriginalReplaced::class, function (OriginalReplaced $event) use (&$events) {
            $events[] = $event;
        });

        $again = $this->mediatheque()->replaceOriginal($media, $this->videoFile('new cut'));

        $this->assertSame([$original?->path(), $original?->checksum()], [$this->freshMedia($again)->original()?->path(), $this->freshMedia($again)->original()?->checksum()]);
        $this->assertCount($runs, $this->runsOf($media, 'video'), 'The pipeline ran once.');
        $this->assertSame([], $events);
        $this->assertSame(MediaStatus::Ready, $this->freshMedia($media)->status());
    }
}
