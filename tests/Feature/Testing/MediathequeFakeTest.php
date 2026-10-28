<?php

namespace Folklore\Mediatheque\Tests\Feature\Testing;

use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Mediatheque as MediathequeContract;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\IngestRejected;
use Folklore\Mediatheque\Events\MediaCreated;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Events\MediaProbed;
use Folklore\Mediatheque\Events\MediaReady;
use Folklore\Mediatheque\Events\PipelineCompleted;
use Folklore\Mediatheque\Events\PipelineFailed;
use Folklore\Mediatheque\Events\PipelineStarted;
use Folklore\Mediatheque\Events\RenditionCompleted;
use Folklore\Mediatheque\Events\RenditionFailed;
use Folklore\Mediatheque\Events\RenditionProgressed;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Testing\FakeProber;
use Folklore\Mediatheque\Testing\MediathequeFake;
use Folklore\Mediatheque\Testing\Models\FakeMedia;
use Folklore\Mediatheque\Tests\Contracts\ReplacesOriginalsContractTests;
use Folklore\Mediatheque\Tests\Contracts\ResolvesReferencesContractTests;
use Folklore\Mediatheque\Tests\Contracts\RunActionsContractTests;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\Test;

class MediathequeFakeTest extends TestCase
{
    use ReplacesOriginalsContractTests;
    use ResolvesReferencesContractTests;
    use RunActionsContractTests;

    protected function mediatheque(): MediathequeContract
    {
        return Mediatheque::fake();
    }

    protected function createMedia(): Media
    {
        return Mediatheque::fake()->ingest(UploadedFile::fake()->create('clip.mp4', 10, 'video/mp4'));
    }

    protected function internalId(Media $media): int
    {
        $this->assertInstanceOf(FakeMedia::class, $media);

        return $media->id;
    }

    protected function probedVideo(): Media
    {
        return Mediatheque::fake()->ingest($this->video());
    }

    protected function videoWhoseProbeFailed(): Media
    {
        return Mediatheque::fake()->failProbe()->ingest($this->video());
    }

    protected function failRenditions(array $renditions): void
    {
        $fake = Mediatheque::fake();
        $fake->transcoder()->failures = [];
        foreach ($renditions as $rendition) {
            $fake->failRendition($rendition);
        }
    }

    protected function holdRuns(): void
    {
        Mediatheque::fake()->holdPipelines();
    }

    protected function probesSucceed(): void
    {
        $prober = Mediatheque::fake()->prober();
        $this->assertInstanceOf(FakeProber::class, $prober);
        $prober->failure = null;
    }

    protected function freshRun(PipelineRun $run): PipelineRun
    {
        return $run;
    }

    protected function freshMedia(Media $media): Media
    {
        return $media;
    }

    protected function videoFile(string $variant): UploadedFile|Source|string
    {
        return UploadedFile::fake()->createWithContent('new-cut.mp4', 'video '.$variant)->mimeType('video/mp4');
    }

    protected function imageFile(): UploadedFile|Source|string
    {
        return UploadedFile::fake()->createWithContent('photo.jpg', 'image')->mimeType('image/jpeg');
    }

    protected function unknownFile(): UploadedFile|Source|string
    {
        return UploadedFile::fake()->createWithContent('notes.txt', 'Just some text');
    }

    protected function runsOf(Media $media, string $pipeline): array
    {
        return array_values(Mediatheque::fake()->runs($media, $pipeline)->all());
    }

    private function video(string $name = 'clip.mp4'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 2048, 'video/mp4');
    }

    #[Test]
    public function fake_returns_the_same_fake_and_binds_the_contracts(): void
    {
        $fake = Mediatheque::fake();

        $this->assertSame($fake, Mediatheque::fake());
        $this->assertSame($fake, app(MediathequeContract::class));
        $this->assertSame($fake->ingestor(), app(Ingestor::class));
    }

    #[Test]
    public function it_ingests_a_video_and_runs_its_pipeline(): void
    {
        Event::fake();
        Mediatheque::fake();

        $media = Mediatheque::ingest($this->video('Episode 1.mp4'), name: 'Episode 1');

        $this->assertTrue(Uid::isValid($media->uid(), 'med'));
        $this->assertSame('video', $media->type());
        $this->assertSame('Episode 1', $media->name());
        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertSame(Visibility::Public, $media->visibility());
        $this->assertSame('Episode 1.mp4', $media->metadata('filename'));
        $this->assertSame('video', $media->metadata('kind'));
        $this->assertSame(1920, $media->metadata('width'));

        $original = $media->original();
        $this->assertNotNull($original);
        $this->assertSame($media->uid().'/original.mp4', $original->path());
        Storage::disk('public')->assertExists($media->uid().'/original.mp4');

        $this->assertNotNull($media->file('h264'));
        $this->assertNotNull($media->file('poster'));
        $this->assertCount(5, $media->files('thumbnails'));
        $this->assertSame('poster', $media->thumbnail()?->role());
        $this->assertSame($media->file('h264')->url(), $media->url());

        Mediatheque::assertIngested(fn (Media $ingested) => $ingested->type() === 'video');
        Mediatheque::assertIngested(times: 1);
        Mediatheque::assertPipelineRan($media);
        Mediatheque::assertPipelineRan($media, 'video');
        Mediatheque::assertPipelineNotRan($media, 'audio');
        Mediatheque::assertRendition($media, 'h264', JobStatus::Completed);

        Event::assertDispatched(MediaCreated::class);
        Event::assertDispatched(MediaProbed::class, fn (MediaProbed $event) => $event->metadata['kind'] === 'video');
        Event::assertDispatched(PipelineStarted::class);
        Event::assertDispatchedTimes(RenditionCompleted::class, 3);
        Event::assertDispatched(PipelineCompleted::class);
        Event::assertDispatched(MediaReady::class);
    }

    #[Test]
    public function the_pipeline_starts_after_the_probe(): void
    {
        $order = [];
        Event::listen(MediaCreated::class, function () use (&$order) {
            $order[] = 'created';
        });
        Event::listen(MediaProbed::class, function () use (&$order) {
            $order[] = 'probed';
        });
        Event::listen(PipelineStarted::class, function () use (&$order) {
            $order[] = 'pipeline';
        });
        Event::listen(MediaReady::class, function () use (&$order) {
            $order[] = 'ready';
        });
        Mediatheque::fake();

        Mediatheque::ingest($this->video());

        $this->assertSame(['created', 'probed', 'pipeline', 'ready'], $order);
    }

    #[Test]
    public function an_image_is_ready_once_probed(): void
    {
        Event::fake();
        Mediatheque::fake();

        $media = Mediatheque::ingest(UploadedFile::fake()->create('photo.jpg', 100, 'image/jpeg'));

        $this->assertSame('image', $media->type());
        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertSame([], $media->files('thumbnails')->all());
        Mediatheque::assertPipelineNotRan($media);
        Event::assertDispatched(MediaReady::class);
        Event::assertNotDispatched(PipelineStarted::class);
    }

    #[Test]
    public function a_file_that_matches_no_type_is_rejected(): void
    {
        Event::fake([IngestRejected::class]);
        Mediatheque::fake();

        try {
            Mediatheque::ingest(UploadedFile::fake()->create('archive.zip', 10, 'application/zip'));
            $this->fail('The file was not rejected.');
        } catch (FileRejected $exception) {
            $this->assertSame(RejectionReason::UnknownType, $exception->reason);
        }

        Mediatheque::assertNothingIngested();
        Event::assertDispatched(IngestRejected::class, fn (IngestRejected $event) => $event->reason === RejectionReason::UnknownType && $event->filename === 'archive.zip');
    }

    #[Test]
    public function a_failed_rendition_fails_the_media_when_it_is_the_main_one(): void
    {
        Event::fake();
        Mediatheque::fake()->failRendition('h264', TranscoderErrorCode::UnsupportedInput);

        $media = Mediatheque::ingest($this->video());

        $this->assertSame(MediaStatus::Failed, $media->status());
        $this->assertNull($media->file('h264'));
        $this->assertNotNull($media->file('poster'), 'Independent renditions continue.');
        Mediatheque::assertRendition($media, 'h264', JobStatus::Failed);
        Event::assertDispatched(RenditionFailed::class, fn (RenditionFailed $event) => $event->error->code === TranscoderErrorCode::UnsupportedInput);
        Event::assertDispatched(PipelineFailed::class);
        Event::assertDispatched(MediaFailed::class);
        Event::assertNotDispatched(MediaReady::class);
    }

    #[Test]
    public function a_skipped_rendition_is_not_a_failure(): void
    {
        Mediatheque::fake()->skipRendition('thumbnails');

        $media = Mediatheque::ingest($this->video());

        $this->assertSame(MediaStatus::Ready, $media->status());
        Mediatheque::assertRendition($media, 'thumbnails', JobStatus::Skipped);
    }

    #[Test]
    public function conditions_are_checked_against_the_probe(): void
    {
        config(['mediatheque.pipelines.video.renditions.highlights' => ['op' => 'video.highlights', 'when' => ['min_duration' => 60]]]);
        Mediatheque::fake()->probeWith(['duration' => 12.5]);

        $media = Mediatheque::ingest($this->video());

        $this->assertSame(12.5, $media->metadata('duration'));
        Mediatheque::assertRendition($media, 'highlights', JobStatus::Skipped);
        Mediatheque::assertRendition($media, 'h264', JobStatus::Completed);
    }

    #[Test]
    public function a_failed_probe_fails_the_media_before_its_pipeline(): void
    {
        Event::fake();
        Mediatheque::fake()->failProbe();

        $media = Mediatheque::ingest($this->video());

        $this->assertSame(MediaStatus::Failed, $media->status());
        Mediatheque::assertPipelineNotRan($media);
        Event::assertDispatched(MediaFailed::class, fn (MediaFailed $event) => $event->error?->code === TranscoderErrorCode::InputUnreadable);
        Event::assertNotDispatched(MediaProbed::class);
    }

    #[Test]
    public function held_pipelines_keep_the_media_processing_until_released(): void
    {
        $fake = Mediatheque::fake()->holdPipelines();

        $media = Mediatheque::ingest($this->video());
        $this->assertSame(MediaStatus::Processing, $media->status());
        Mediatheque::assertRendition($media, 'h264', JobStatus::Queued);

        $fake->releasePipelines();
        $this->assertSame(MediaStatus::Ready, $media->status());
    }

    #[Test]
    public function a_held_rendition_reports_progress_that_a_test_can_assert(): void
    {
        Event::fake([RenditionProgressed::class]);
        $fake = Mediatheque::fake()->holdPipelines();
        $media = Mediatheque::ingest($this->video());
        $this->assertNull($media->processing()?->progress, 'Nothing measured yet.');

        $fake->progressRendition($media, 'h264', 0.42);

        Event::assertDispatched(RenditionProgressed::class, fn (RenditionProgressed $event) => $event->media()->uid() === $media->uid() && $event->rendition() === 'h264' && $event->progress === 0.42);
        $processing = $media->processing();
        $this->assertNotNull($processing);
        $this->assertSame('h264', $processing->rendition);
        $this->assertNotNull($processing->progress);

        $fake->releasePipelines();
        $this->assertNull($media->processing());
    }

    #[Test]
    public function a_private_media_gets_temporary_urls(): void
    {
        Mediatheque::fake();

        $media = Mediatheque::ingest($this->video(), visibility: Visibility::Private);

        $this->assertSame(Visibility::Private, $media->file('h264')?->visibility());
        $this->assertStringContainsString('expires=', (string) $media->url());
        $this->assertStringContainsString('expires=', (string) $media->url('poster'));
    }

    #[Test]
    public function running_a_pipeline_again_only_produces_what_is_missing_or_changed(): void
    {
        $fake = Mediatheque::fake();
        $media = $fake->ingest($this->video());

        $this->assertNull($fake->runPipeline($media), 'Nothing changed.');

        config(['mediatheque.pipelines.video.renditions.poster' => ['op' => 'video.poster', 'role' => 'poster', 'at' => 2.0]]);
        $run = $fake->runPipeline($media, 'video');
        $this->assertNotNull($run);
        $this->assertSame(['poster'], array_keys($run->renditions()));

        $forced = $fake->runPipeline($media, renditions: ['h264'], force: true);
        $this->assertNotNull($forced);
        $this->assertSame(['h264'], array_keys($forced->renditions()));
        $this->assertCount(3, $fake->runs($media));
    }

    #[Test]
    public function the_ingestor_contract_goes_through_the_fake(): void
    {
        Mediatheque::fake();

        $media = app(Ingestor::class)->ingest(Source::path(self::fixturePath('media/test.mp4')), new IngestOptions(pipeline: 'video'));

        $this->assertSame('video', $media->type());
        $this->assertSame('test.mp4', $media->metadata('filename'));
        Mediatheque::assertIngested(times: 1);
    }

    #[Test]
    public function types_come_from_the_content_of_local_files(): void
    {
        Mediatheque::fake();

        $this->assertSame('audio', Mediatheque::ingest(self::fixturePath('media/test.wav'))->type());
        $this->assertSame('font', Mediatheque::ingest(self::fixturePath('media/font.otf'))->type());

        Storage::fake('uploads');
        Storage::disk('uploads')->put('incoming/no-extension', (string) file_get_contents(self::fixturePath('media/image.jpg')));
        $this->assertSame('image', Mediatheque::ingest(Source::disk('uploads', 'incoming/no-extension'))->type());
    }

    #[Test]
    public function the_fake_replaces_an_original_through_the_media_and_deletes_the_previous_files(): void
    {
        $media = Mediatheque::fake()->ingest($this->video());
        $this->assertInstanceOf(FakeMedia::class, $media);
        $previous = $media->files()->map(fn ($file) => (string) $file->path())->all();
        $this->assertCount(8, $previous, 'The original, h264, the poster and five thumbnails.');

        $replaced = $media->replaceOriginal($this->videoFile('new cut'), new IngestOptions(name: 'New cut'));

        $this->assertSame($media, $replaced);
        $this->assertSame(['New cut', MediaStatus::Ready], [$media->name(), $media->status()]);
        $this->assertStringStartsWith($media->uid.'/original-', (string) $media->original()?->path());
        Storage::disk('public')->assertExists((string) $media->original()?->path());
        foreach ($previous as $path) {
            Storage::disk('public')->assertMissing($path);
        }
        Mediatheque::assertPipelineRan($media, 'video');
    }

    #[Test]
    public function assertions_fail_when_they_should(): void
    {
        $fake = Mediatheque::fake();

        $this->assertInstanceOf(MediathequeFake::class, $fake);
        $this->expectException(AssertionFailedError::class);

        $fake->assertIngested();
    }
}
