<?php

namespace Folklore\Mediatheque\Tests\Feature\Transcoders;

use Folklore\Mediatheque\Contracts\Mediatheque as MediathequeContract;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Events\MediaReady;
use Folklore\Mediatheque\Events\PipelineCompleted;
use Folklore\Mediatheque\Events\RenditionCompleted;
use Folklore\Mediatheque\Events\RenditionProgressed;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Jobs\RunRendition;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Transcoders\Ffmpeg\FfmpegTranscoder;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Process\Process;

/**
 * The whole flow on fixtures, with the sync queue: ingest, probe, pipeline,
 * renditions. One test per operation of the driver.
 */
class FfmpegTranscoderTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->requiresBinary('ffmpeg');
        $this->requiresBinary('ffprobe');
        Storage::fake('public');
    }

    private function ingest(string $fixture, string $name, ?Visibility $visibility = null, ?string $pipeline = null): Media
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'upload');
        copy(self::fixturePath($fixture), $path);

        $media = Mediatheque::ingest(new UploadedFile($path, $name, null, null, true), visibility: $visibility, pipeline: $pipeline);
        $this->assertInstanceOf(Media::class, $media);

        return Media::query()->with('mediaFiles')->findOrFail($media->id);
    }

    /**
     * What ffprobe reads from a stored file.
     *
     * @return array<array-key, mixed>
     */
    private function ffprobe(File $file): array
    {
        $process = new Process(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', Storage::disk('public')->path((string) $file->path())]);
        $process->mustRun();

        $output = json_decode($process->getOutput(), true);
        $this->assertIsArray($output);

        return $output;
    }

    #[Test]
    public function the_package_registers_the_ffmpeg_transcoder(): void
    {
        $driver = app(TranscoderManager::class)->driver('ffmpeg');

        $this->assertInstanceOf(FfmpegTranscoder::class, $driver);
        $this->assertInstanceOf(Transcoder::class, $driver);
        $this->assertTrue($driver->supports('video.transcode'));
        $this->assertFalse($driver->supports('video.hls'));
    }

    #[Test]
    public function an_uploaded_video_is_transcoded_and_ready_to_play(): void
    {
        Event::fake([MediaReady::class, PipelineCompleted::class, RenditionCompleted::class]);
        $before = glob(LocalFiles::root().'/*') ?: [];

        $media = $this->ingest('media/test.mp4', 'clip.mp4');

        $this->assertSame(MediaStatus::Ready, $media->status());
        $run = PipelineRun::query()->where('name', '!=', 'probe')->sole();
        $this->assertSame(PipelineStatus::Completed, $run->status());
        $this->assertSame(['h264', 'poster', 'thumbnails'], array_keys($run->renditions()));

        $h264 = $media->file('h264');
        $this->assertNotNull($h264);
        $this->assertStringStartsWith($run->pathPrefix().'/', (string) $h264->path());
        $this->assertSame(['video/mp4', 320, 180], [$h264->mime(), $h264->width(), $h264->height()]);
        $this->assertSame(hash('sha256', (string) Storage::disk('public')->get((string) $h264->path())), $h264->checksum());
        $this->assertNotNull($h264->duration());
        $probe = $this->ffprobe($h264);
        $this->assertSame('h264', data_get($probe, 'streams.0.codec_name'));
        $this->assertSame($media->url(), $h264->url(), 'The media plays its main rendition.');

        $poster = $media->file('poster');
        $this->assertSame(['image/jpeg', 'poster', 320, 180], [$poster?->mime(), $poster?->role(), $poster?->width(), $poster?->height()]);
        $this->assertSame($poster?->path(), $media->thumbnail()?->path());

        $thumbnails = $media->files('thumbnails');
        $this->assertSame([0, 1, 2, 3, 4], $thumbnails->map(fn (File $file) => $file->index())->all());
        $this->assertSame(['thumbnail'], $thumbnails->map(fn (File $file) => $file->role())->unique()->values()->all());
        foreach ($thumbnails as $thumbnail) {
            Storage::disk('public')->assertExists((string) $thumbnail->path());
        }

        Event::assertDispatchedTimes(MediaReady::class, 1);
        Event::assertDispatchedTimes(PipelineCompleted::class, 2);
        Event::assertDispatched(PipelineCompleted::class, fn (PipelineCompleted $event) => $event->run->name() === 'probe');
        Event::assertDispatchedTimes(RenditionCompleted::class, 4);
        $this->assertSame($before, glob(LocalFiles::root().'/*') ?: [], 'No temporary file is left.');
    }

    #[Test]
    public function a_vertical_video_is_turned_upright_and_fitted(): void
    {
        config(['mediatheque.pipelines.video.renditions' => [
            'h264' => ['op' => 'video.transcode', 'codec' => 'h264', 'max_width' => 360, 'preset' => 'ultrafast'],
        ]]);

        $media = $this->ingest('media/test_vertical_hevc.mp4', 'portrait.mp4');

        $h264 = $media->file('h264');
        $this->assertSame([360, 640], [$h264?->width(), $h264?->height()]);
        $this->assertSame(MediaStatus::Ready, $media->status());
    }

    #[Test]
    public function a_private_media_has_private_renditions(): void
    {
        $media = $this->ingest('media/test.mp4', 'clip.mp4', Visibility::Private);

        foreach ($media->files() as $file) {
            $this->assertSame('private', Storage::disk('public')->getVisibility((string) $file->path()), $file->handle());
        }
    }

    #[Test]
    public function audio_is_transcoded_to_mp3_with_a_waveform(): void
    {
        $media = $this->ingest('media/test.wav', 'song.wav');

        $this->assertSame(MediaStatus::Ready, $media->status());
        $mp3 = $media->file('mp3');
        $this->assertNotNull($mp3);
        $this->assertSame('audio/mpeg', $mp3->mime());
        $this->assertSame('mp3', data_get($this->ffprobe($mp3), 'streams.0.codec_name'));
        $this->assertSame(['image/png', 1200, 240], [$media->file('waveform')?->mime(), $media->file('waveform')?->width(), $media->file('waveform')?->height()]);
        $this->assertSame('waveform', $media->thumbnail()?->handle());
    }

    #[Test]
    public function a_rendition_reads_from_another_one_of_the_same_run(): void
    {
        config(['mediatheque.pipelines.video.renditions' => [
            'small' => ['op' => 'video.transcode', 'max_width' => 160, 'preset' => 'ultrafast', 'keep' => false],
            'h264' => ['op' => 'video.transcode', 'max_width' => 1920, 'preset' => 'ultrafast', 'from' => 'small'],
            'poster' => ['op' => 'video.poster', 'role' => 'poster', 'from' => 'h264'],
        ]]);

        $media = $this->ingest('media/test.mp4', 'clip.mp4');

        $this->assertNull($media->file('small'), 'A rendition that is not kept has no file.');
        $this->assertSame([160, 90], [$media->file('h264')?->width(), $media->file('h264')?->height()], 'It read from the small one.');
        $this->assertSame(160, $media->file('poster')?->width());
        $this->assertSame(MediaStatus::Ready, $media->status());
    }

    #[Test]
    public function a_failed_main_rendition_fails_the_media_and_the_renditions_that_read_from_it(): void
    {
        Event::fake([MediaFailed::class]);
        config(['mediatheque.pipelines.video.renditions' => [
            'h264' => ['op' => 'video.transcode', 'codec' => 'theora'],
            'poster' => ['op' => 'video.poster', 'role' => 'poster', 'from' => 'h264'],
            'thumbnails' => ['op' => 'video.thumbnails', 'count' => 2, 'role' => 'thumbnail'],
        ]]);

        $media = $this->ingest('media/test.mp4', 'clip.mp4');

        $run = PipelineRun::query()->where('name', '!=', 'probe')->sole();
        $this->assertSame(MediaStatus::Failed, $media->status());
        $this->assertSame(PipelineStatus::Failed, $run->status());
        $this->assertSame(TranscoderErrorCode::DependencyFailed, $run->job('poster')?->error()?->code);
        $this->assertSame(JobStatus::Completed, $run->job('thumbnails')?->status(), 'Independent renditions continue.');
        Event::assertDispatched(MediaFailed::class, fn (MediaFailed $event) => $event->error?->code === TranscoderErrorCode::OperationFailed);
    }

    #[Test]
    public function a_rendition_whose_condition_does_not_match_is_skipped(): void
    {
        config(['mediatheque.pipelines.video.renditions' => [
            'h264' => ['op' => 'video.transcode', 'preset' => 'ultrafast'],
            'long' => ['op' => 'video.poster', 'role' => 'poster', 'when' => ['min_duration' => 60]],
        ]]);

        $media = $this->ingest('media/test.mp4', 'clip.mp4');

        $run = PipelineRun::query()->where('name', '!=', 'probe')->sole();
        $this->assertSame(JobStatus::Skipped, $run->job('long')?->status());
        $this->assertSame(PipelineStatus::Completed, $run->status());
        $this->assertSame(MediaStatus::Ready, $media->status());
    }

    #[Test]
    public function a_retried_job_of_an_ended_rendition_changes_nothing(): void
    {
        $media = $this->ingest('media/test.mp4', 'clip.mp4');
        $run = PipelineRun::query()->where('name', '!=', 'probe')->sole();
        $files = $media->files()->map(fn (File $file) => $file->path())->all();

        app()->call([new RunRendition($run->id, 'h264'), 'handle']);

        $this->assertSame($files, Media::query()->findOrFail($media->id)->files()->map(fn (File $file) => $file->path())->all());
        $this->assertSame(1, $run->job('h264')?->attempts());
    }

    #[Test]
    public function running_the_pipeline_again_only_produces_what_changed(): void
    {
        $media = $this->ingest('media/test.mp4', 'clip.mp4');

        $mediatheque = app(MediathequeContract::class);
        $this->assertNull($mediatheque->runPipeline($media));

        config(['mediatheque.pipelines.video.renditions.poster' => ['op' => 'video.poster', 'role' => 'poster', 'time' => 0.5]]);
        $rerun = $mediatheque->runPipeline($media);

        $this->assertNotNull($rerun);
        $this->assertSame(['poster'], array_keys($rerun->renditions()));
        $this->assertSame(PipelineStatus::Completed, PipelineRun::query()->where('uid', $rerun->key())->sole()->status());
        $this->assertStringStartsWith($rerun->pathPrefix().'/', (string) Media::query()->findOrFail($media->id)->file('poster')?->path());
    }

    #[Test]
    public function an_ingest_can_choose_its_pipeline(): void
    {
        config(['mediatheque.pipelines.clip' => ['renditions' => [
            'poster' => ['op' => 'video.poster', 'role' => 'poster'],
        ]]]);

        $media = $this->ingest('media/test.mp4', 'clip.mp4', pipeline: 'clip');

        $this->assertSame('clip', PipelineRun::query()->where('name', '!=', 'probe')->sole()->name());
        $this->assertNotNull($media->file('poster'));
        $this->assertSame(MediaStatus::Ready, $media->status(), 'A pipeline without a main rendition makes the media ready once probed.');
    }

    /**
     * What a transcode of the fixture reports to its progress callback.
     *
     * @param  array<string, mixed>  $metadata
     * @return list<float>
     */
    private function transcodeReports(array $metadata): array
    {
        $reports = [];
        $directory = app(LocalFiles::class)->temporaryDirectory();

        try {
            app(FfmpegTranscoder::class)->operation('video.transcode')->run(new OperationContext(
                input: self::fixturePath('media/test.mp4'),
                outputDirectory: $directory,
                rendition: Rendition::fromArray('h264', ['op' => 'video.transcode', 'preset' => 'ultrafast']),
                metadata: $metadata,
                progress: function (float $progress) use (&$reports) {
                    $reports[] = $progress;
                },
                timeout: 60,
            ));
        } finally {
            LocalFiles::removeDirectory($directory);
        }

        return $reports;
    }

    #[Test]
    public function a_transcode_reports_its_progress_only_when_the_input_duration_is_known(): void
    {
        $this->assertSame([], $this->transcodeReports([]), 'Without a duration, ffmpeg measures nothing: the rendition\'s progress stays null until it ends.');
        $this->assertNotSame([], $this->transcodeReports(['duration' => 1.0]));
    }

    #[Test]
    public function a_transcode_dispatches_its_progress(): void
    {
        Event::fake([RenditionProgressed::class]);

        $this->ingest('media/test.mp4', 'clip.mp4');

        Event::assertDispatched(RenditionProgressed::class, fn (RenditionProgressed $event) => $event->rendition() === 'h264' && $event->progress > 0 && $event->progress < 1);
    }
}
