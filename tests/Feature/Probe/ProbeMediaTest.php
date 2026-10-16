<?php

namespace Folklore\Mediatheque\Tests\Feature\Probe;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Events\MediaProbed;
use Folklore\Mediatheque\Events\MediaReady;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Probe\ProbeOutcome;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Feature\Models\Fixtures\TenantMedia;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class ProbeMediaTest extends DatabaseTestCase
{
    use CreatesMedia;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    /**
     * A pending media whose original is a fixture, stored without a
     * checksum, as an original ingested from a disk is.
     */
    private function pendingMedia(string $type, string $fixture, string $mime): Media
    {
        $media = $this->createMedia(['type' => $type, 'status' => MediaStatus::Pending]);
        $path = $type.'/'.$media->uid.'/original.'.pathinfo($fixture, PATHINFO_EXTENSION);
        Storage::disk('public')->put($path, (string) file_get_contents(self::fixturePath($fixture)));
        $this->createFile($media, 'original', ['path' => $path, 'mime' => $mime, 'checksum' => null]);

        return $media;
    }

    private function probe(Media $media): void
    {
        app()->call([new ProbeMedia($media->id), 'handle']);
    }

    private function fresh(Media $media): Media
    {
        return Media::query()->withoutGlobalScopes()->findOrFail($media->id);
    }

    /**
     * @param  callable(FileContract): ProbeOutcome  $probe
     */
    private function fakeProber(callable $probe): void
    {
        app()->instance(Prober::class, new class($probe) implements Prober
        {
            /** @var callable(FileContract): ProbeOutcome */
            private $probe;

            public function __construct(callable $probe)
            {
                $this->probe = $probe;
            }

            public function probe(FileContract $file): ProbeOutcome
            {
                return ($this->probe)($file);
            }
        });
    }

    #[Test]
    public function a_probed_video_is_processing_with_its_metadata(): void
    {
        $this->requiresBinary('ffprobe');
        Event::fake([MediaProbed::class, MediaReady::class]);
        $media = $this->pendingMedia('video', 'media/test.mp4', 'video/mp4');

        $this->probe($media);

        $media = $this->fresh($media);
        $original = $media->original();
        $this->assertSame(MediaStatus::Processing, $media->status());
        $this->assertSame('video', $media->metadata('kind'));
        $this->assertSame(320, $media->metadata('width'));
        $this->assertNotNull($original);
        $this->assertSame([320, 180, 1.0, 397480], [$original->width(), $original->height(), $original->duration(), $original->bitrate()]);
        $this->assertSame(hash_file('sha256', self::fixturePath('media/test.mp4')), $original->checksum());
        $this->assertSame($original->checksum(), $media->metadata('checksum'));
        Event::assertDispatched(MediaProbed::class, fn (MediaProbed $event) => $event->media->uid() === $media->uid && $event->metadata['kind'] === 'video');
        Event::assertNotDispatched(MediaReady::class);
    }

    #[Test]
    public function an_image_is_ready_once_probed(): void
    {
        $this->requiresImagick();
        Event::fake([MediaProbed::class, MediaReady::class]);
        $media = $this->pendingMedia('image', 'media/image.jpg', 'image/jpeg');

        $this->probe($media);

        $this->assertSame(MediaStatus::Ready, $this->fresh($media)->status());
        $this->assertSame(800, $this->fresh($media)->original()?->width());
        Event::assertDispatched(MediaProbed::class);
        Event::assertDispatched(MediaReady::class);
    }

    #[Test]
    public function a_failed_probe_fails_the_media(): void
    {
        Event::fake([MediaFailed::class]);
        $this->fakeProber(fn () => ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::InputUnreadable, 'Unreadable')));
        $media = $this->pendingMedia('video', 'media/test.mp4', 'video/mp4');

        $this->probe($media);

        $this->assertSame(MediaStatus::Failed, $this->fresh($media)->status());
        Event::assertDispatched(MediaFailed::class, fn (MediaFailed $event) => $event->error?->code === TranscoderErrorCode::InputUnreadable);
    }

    #[Test]
    public function a_media_that_is_not_pending_is_left_alone(): void
    {
        Event::fake([MediaProbed::class]);
        $calls = 0;
        $this->fakeProber(function () use (&$calls) {
            $calls++;

            return ProbeOutcome::done(['kind' => 'video']);
        });
        $media = $this->pendingMedia('video', 'media/test.mp4', 'video/mp4');

        $this->probe($media);
        $this->probe($media);

        $this->assertSame(1, $calls);
        Event::assertDispatchedTimes(MediaProbed::class, 1);
    }

    #[Test]
    public function the_media_is_found_whatever_the_global_scopes(): void
    {
        config(['mediatheque.models.media' => TenantMedia::class]);
        $this->fakeProber(fn () => ProbeOutcome::done(['kind' => 'video']));
        $media = $this->pendingMedia('video', 'media/test.mp4', 'video/mp4');
        TenantMedia::$scoped = true;

        try {
            $this->probe($media);
        } finally {
            TenantMedia::$scoped = false;
        }

        $this->assertSame(MediaStatus::Processing, $this->fresh($media)->status());
    }

    #[Test]
    public function a_pending_probe_is_recorded_as_a_run_of_the_probe_pipeline(): void
    {
        $this->fakeProber(fn () => ProbeOutcome::pending('job-42'));
        $media = $this->pendingMedia('video', 'media/test.mp4', 'video/mp4');

        $this->probe($media);

        $run = PipelineRun::query()->sole();
        $this->assertSame(MediaStatus::Pending, $this->fresh($media)->status());
        $this->assertSame(['probe', 'job-42', $media->uid], [$run->name(), $run->externalId(), $run->media()->uid()]);
        $this->assertSame(JobStatus::Queued, $run->job('probe')?->status());
        $this->assertStringContainsString($run->key(), $run->pathPrefix());
    }

    #[Test]
    public function the_media_fails_after_the_last_try(): void
    {
        Event::fake([MediaFailed::class]);
        $media = $this->pendingMedia('video', 'media/test.mp4', 'video/mp4');

        (new ProbeMedia($media->id))->failed(new RuntimeException('ffprobe is missing'));

        $this->assertSame(MediaStatus::Failed, $this->fresh($media)->status());
        Event::assertDispatched(MediaFailed::class, fn (MediaFailed $event) => str_contains((string) $event->error?->message, 'ffprobe is missing'));
    }

    #[Test]
    public function the_job_runs_on_the_configured_queue(): void
    {
        config(['mediatheque.jobs' => ['connection' => 'redis', 'queue' => 'media', 'tries' => 5, 'timeout' => 600]]);

        $job = new ProbeMedia(1);

        $this->assertSame(['redis', 'media', 5, 600], [$job->connection, $job->queue, $job->tries, $job->timeout]);
    }
}
