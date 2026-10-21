<?php

namespace Folklore\Mediatheque\Tests\Contracts;

use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mediatheque::retry(), cancel(), refresh() and probeAgain()
 * (docs/decisions/0017), on the default `video` pipeline: `h264`, `poster`
 * and `thumbnails`.
 */
trait RunActionsContractTests
{
    abstract protected function mediatheque(): Mediatheque;

    /**
     * A probed video, `ready` or `processing`.
     */
    abstract protected function probedVideo(): Media;

    /**
     * A video whose probe failed.
     */
    abstract protected function videoWhoseProbeFailed(): Media;

    /**
     * Renditions of the runs created from now on fail. None when empty.
     *
     * @param  list<string>  $renditions
     */
    abstract protected function failRenditions(array $renditions): void;

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

    private function runVideo(Media $media): PipelineRun
    {
        $run = $this->mediatheque()->runPipeline($media, 'video', force: true);
        $this->assertNotNull($run);

        return $this->freshRun($run);
    }

    #[Test]
    public function retry_runs_the_failed_renditions_again_in_a_new_run(): void
    {
        $media = $this->probedVideo();
        $this->failRenditions(['poster']);
        $failed = $this->runVideo($media);
        $this->assertSame(PipelineStatus::Failed, $failed->status());

        $this->failRenditions([]);
        $retry = $this->mediatheque()->retry($failed);

        $this->assertNotNull($retry);
        $retry = $this->freshRun($retry);
        $this->assertNotSame($failed->key(), $retry->key());
        $this->assertSame(['poster'], array_keys($retry->renditions()));
        $this->assertSame(PipelineStatus::Completed, $retry->status());
        $this->assertSame(PipelineStatus::Failed, $this->freshRun($failed)->status(), 'A run is never retried in place.');
    }

    #[Test]
    public function retry_produces_a_rendition_again_even_if_a_file_exists_for_it(): void
    {
        $media = $this->probedVideo();
        $this->runVideo($media);
        $this->failRenditions(['h264']);
        $failed = $this->runVideo($media);

        $this->failRenditions([]);
        $retry = $this->mediatheque()->retry($failed);

        $this->assertNotNull($retry);
        $this->assertSame(JobStatus::Completed, $this->freshRun($retry)->job('h264')?->status());
    }

    #[Test]
    public function retry_creates_nothing_for_a_run_that_has_not_ended_or_has_nothing_to_retry(): void
    {
        $media = $this->probedVideo();
        $completed = $this->runVideo($media);
        $this->holdRuns();
        $running = $this->runVideo($media);

        $this->assertNull($this->mediatheque()->retry($running));
        $this->assertNull($this->mediatheque()->retry($completed));
    }

    #[Test]
    public function retry_creates_nothing_while_the_media_has_a_run_of_the_pipeline_in_progress(): void
    {
        $media = $this->probedVideo();
        $this->failRenditions(['poster']);
        $failed = $this->runVideo($media);
        $this->failRenditions([]);
        $this->holdRuns();
        $this->runVideo($media);

        $this->assertNull($this->mediatheque()->retry($failed));
    }

    #[Test]
    public function cancel_cancels_a_run_in_progress(): void
    {
        $this->holdRuns();
        $run = $this->runVideo($this->probedVideo());

        $this->assertTrue($this->mediatheque()->cancel($run));

        $run = $this->freshRun($run);
        $this->assertSame(PipelineStatus::Canceled, $run->status());
        $this->assertSame([JobStatus::Canceled], $run->jobs()->map(fn ($job) => $job->status())->unique()->values()->all());
        $this->assertFalse($this->mediatheque()->cancel($run), 'An ended run is not canceled again.');
    }

    #[Test]
    public function a_canceled_run_can_be_retried(): void
    {
        $media = $this->probedVideo();
        $this->holdRuns();
        $run = $this->runVideo($media);
        $this->mediatheque()->cancel($run);

        $retry = $this->mediatheque()->retry($this->freshRun($run));

        $this->assertNotNull($retry);
        $this->assertSame(['h264', 'poster', 'thumbnails'], array_keys($retry->renditions()));
    }

    #[Test]
    public function an_ended_run_is_not_refreshed(): void
    {
        $run = $this->runVideo($this->probedVideo());

        $this->assertFalse($this->mediatheque()->refresh($run));
    }

    #[Test]
    public function probe_again_probes_a_media_whose_probe_failed(): void
    {
        $media = $this->videoWhoseProbeFailed();
        $this->assertSame(MediaStatus::Failed, $media->status());

        $this->probesSucceed();

        $this->assertTrue($this->mediatheque()->probeAgain($media));
        $media = $this->freshMedia($media);
        $this->assertNotSame(MediaStatus::Failed, $media->status());
        $this->assertSame('video', $media->metadata('kind'));
    }

    #[Test]
    public function probe_again_leaves_a_media_that_was_probed_alone(): void
    {
        $ready = $this->probedVideo();
        $this->failRenditions(['h264']);
        $failed = $this->probedVideo();
        $this->runVideo($failed);
        $failed = $this->freshMedia($failed);
        $this->assertSame(MediaStatus::Failed, $failed->status(), 'Its main rendition failed.');

        $this->assertFalse($this->mediatheque()->probeAgain($ready));
        $this->assertFalse($this->mediatheque()->probeAgain($failed));
        $this->assertSame(MediaStatus::Failed, $this->freshMedia($failed)->status());
    }
}
