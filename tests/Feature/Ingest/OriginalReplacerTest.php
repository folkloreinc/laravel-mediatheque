<?php

namespace Folklore\Mediatheque\Tests\Feature\Ingest;

use Closure;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\IngestRejected;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Events\OriginalReplaced;
use Folklore\Mediatheque\Events\PipelineCanceled;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\OriginalReplacer;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Jobs\DeleteStoredFiles;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\File;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\PipelineRunner;
use Folklore\Mediatheque\Probe\ProbeResults;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Support\ScriptedTranscoder;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Filesystem;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Replacing a media's original on the Eloquent models (docs/decisions/0019).
 * Probes are queued and not run, unless a test runs them.
 */
class OriginalReplacerTest extends DatabaseTestCase
{
    private ScriptedTranscoder $transcoder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Bus::fake([ProbeMedia::class, DeleteStoredFiles::class]);
        $this->transcoder = new ScriptedTranscoder(app(Recorder::class));
        app(TranscoderManager::class)->extend('scripted', fn () => $this->transcoder);
        config(['mediatheque.pipelines.video.transcoder' => 'scripted']);
    }

    /**
     * An upload whose content is a fixture, followed by `$variant`: a
     * different file for each variant.
     */
    private function upload(string $fixture, string $name, string $variant = ''): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upload');
        file_put_contents((string) $path, file_get_contents(self::fixturePath($fixture)).$variant);

        return new UploadedFile((string) $path, $name, 'application/octet-stream', null, true);
    }

    /**
     * A video ingested from an upload, with renditions from a completed run.
     */
    private function video(): Media
    {
        $media = Mediatheque::ingest($this->upload('media/test.mp4', 'clip.mp4'));
        $this->assertInstanceOf(Media::class, $media);
        $media->forceFill(['status' => MediaStatus::Ready, 'metadata' => [...$media->metadata(), 'kind' => 'video', 'width' => 320]])->save();
        $run = app(PipelineRunner::class)->run($media, 'video');
        $this->assertNotNull($run);

        return $media->fresh() ?? $this->fail('The media is gone.');
    }

    /**
     * @return list<string>
     */
    private function storedFiles(): array
    {
        return array_values(Storage::disk('public')->allFiles());
    }

    #[Test]
    public function an_upload_replaces_the_original_and_the_media_is_probed_again(): void
    {
        $media = $this->video();
        $previous = $media->original();
        $renditions = $media->files()->reject(fn (FileContract $file) => $file->handle() === 'original')->map(fn (FileContract $file) => $file->path())->values()->all();
        $this->assertCount(3, $renditions);

        $replaced = Mediatheque::replaceOriginal($media, $this->upload('media/test.mp4', 'new cut.mp4', 'new cut'));

        $this->assertSame($media, $replaced, 'The media given is updated.');
        $original = $media->original();
        $this->assertInstanceOf(File::class, $original);
        $checksum = hash('sha256', file_get_contents(self::fixturePath('media/test.mp4')).'new cut');
        $this->assertSame('video/'.$media->uid.'/original-'.substr($checksum, 0, 16).'.mp4', $original->path());
        $this->assertSame([$checksum, 49685 + 7, 'video/mp4', 'new cut.mp4'], [$original->checksum(), $original->size(), $original->mime(), $original->name]);
        $this->assertSame([MediaStatus::Pending, 'clip.mp4'], [$media->status(), $media->name()]);
        $this->assertSame([
            'filename' => 'new cut.mp4',
            'mime' => 'video/mp4',
            'extension' => 'mp4',
            'size' => 49685 + 7,
            'checksum' => $checksum,
        ], $media->metadata(), 'The probed metadata goes with the previous original.');
        $this->assertSame(['original'], File::query()->where('media_id', $media->id)->pluck('handle')->all(), 'The renditions are removed.');
        Storage::disk('public')->assertExists((string) $original->path());

        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id && $job->pipeline === null);
        $deleted = [$previous?->path(), ...$renditions];
        sort($deleted);
        Bus::assertDispatched(DeleteStoredFiles::class, function (DeleteStoredFiles $job) use ($deleted) {
            $paths = $job->paths;
            sort($paths);

            return $job->disk === 'public' && $paths === $deleted;
        });
    }

    #[Test]
    public function the_previous_files_are_deleted_by_the_queued_job_once_the_new_original_is_stored(): void
    {
        $media = Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'));
        $previous = (string) $media->original()?->path();

        Mediatheque::replaceOriginal($media, $this->upload('media/image.jpg', 'photo.jpg', 'retouched'));

        Storage::disk('public')->assertExists($previous);
        foreach (Bus::dispatched(DeleteStoredFiles::class) as $job) {
            $this->assertInstanceOf(DeleteStoredFiles::class, $job);
            $job->handle(app(Filesystems::class));
        }
        Storage::disk('public')->assertMissing($previous);
        Storage::disk('public')->assertExists((string) $media->original()?->path());
    }

    #[Test]
    public function the_deletion_job_keeps_a_path_that_a_file_records_again(): void
    {
        $media = Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'));
        $path = (string) $media->original()?->path();

        (new DeleteStoredFiles('public', [$path, 'image/gone.jpg']))->handle(app(Filesystems::class));

        Storage::disk('public')->assertExists($path);
    }

    #[Test]
    public function a_path_replaces_the_original(): void
    {
        $media = Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'));
        $this->assertInstanceOf(Media::class, $media);
        $path = tempnam(sys_get_temp_dir(), 'replace');
        file_put_contents((string) $path, file_get_contents(self::fixturePath('media/image.jpg')).'retouched');

        $media->replaceOriginal((string) $path);

        $this->assertSame(hash_file('sha256', (string) $path), $media->original()?->checksum());
        $this->assertSame(basename((string) $path), $media->metadata('filename'));
        $this->assertSame([], glob(sys_get_temp_dir().'/mediatheque/*') ?: [], 'No temporary file is left.');
    }

    #[Test]
    public function a_file_on_the_same_s3_disk_is_copied_on_the_server_and_probed_for_its_checksum(): void
    {
        $this->useMemoryDisk('s3');
        config(['mediatheque.source' => 's3']);
        Storage::disk('s3')->put('uploads/upl_1', (string) file_get_contents(self::fixturePath('media/test.mp4')));
        $media = Mediatheque::ingest(Source::disk('s3', 'uploads/upl_1', 'clip.mp4'));
        Storage::disk('s3')->put('uploads/upl_2', file_get_contents(self::fixturePath('media/test.mp4')).'new cut');

        Mediatheque::replaceOriginal($media, Source::disk('s3', 'uploads/upl_2', 'new cut.mp4'));

        $original = $media->original();
        $this->assertNotNull($original);
        $this->assertSame(['s3', null, 49685 + 7, 'new cut.mp4'], [$original->source(), $original->checksum(), $original->size(), $media->metadata('filename')]);
        $this->assertStringStartsWith('video/'.$media->uid().'/original-', (string) $original->path());
        $this->assertSame(Storage::disk('s3')->get('uploads/upl_2'), Storage::disk('s3')->get((string) $original->path()));
        $this->assertArrayNotHasKey('checksum', $media->metadata(), 'The probe computes it, without reading the file in the request.');
        Bus::assertDispatchedTimes(ProbeMedia::class, 2);

        Mediatheque::replaceOriginal($media, Source::disk('s3', 'uploads/upl_2', 'new cut.mp4'));

        $this->assertSame($original->path(), $media->original()?->path(), 'The same file on the disk is the original already.');
        Bus::assertDispatchedTimes(ProbeMedia::class, 2);
        $this->assertCount(4, Storage::disk('s3')->allFiles(), 'Two uploads, and the first original until its deletion job runs.');
    }

    #[Test]
    public function a_rejected_file_changes_nothing(): void
    {
        Event::fake([IngestRejected::class, OriginalReplaced::class]);
        $media = $this->video();
        $files = File::query()->orderBy('id')->get()->toArray();
        $stored = $this->storedFiles();
        config(['mediatheque.types.video.max_size' => 1000]);

        try {
            Mediatheque::replaceOriginal($media, $this->upload('media/test.mp4', 'new cut.mp4', 'new cut'));
            $this->fail('The file was not refused.');
        } catch (FileRejected $exception) {
            $this->assertSame(RejectionReason::TooLarge, $exception->reason);
        }

        $this->assertSame($files, File::query()->orderBy('id')->get()->toArray());
        $this->assertSame($stored, $this->storedFiles());
        $this->assertSame(MediaStatus::Ready, $media->fresh()?->status());
        Event::assertDispatched(IngestRejected::class, fn (IngestRejected $event) => $event->reason === RejectionReason::TooLarge && $event->filename === 'new cut.mp4');
        Event::assertNotDispatched(OriginalReplaced::class);
        Bus::assertDispatchedTimes(ProbeMedia::class, 1);
        Bus::assertNotDispatched(DeleteStoredFiles::class);
    }

    #[Test]
    public function a_type_change_is_refused_by_default_and_allowed_with_the_option(): void
    {
        Event::fake([IngestRejected::class]);
        $media = $this->video();
        $stored = $this->storedFiles();

        try {
            $media->replaceOriginal($this->upload('media/image.jpg', 'poster.jpg'));
            $this->fail('The type changed.');
        } catch (FileRejected $exception) {
            $this->assertSame(RejectionReason::TypeChanged, $exception->reason);
            $this->assertStringContainsString('is a image, and '.$media->uid.' is a video', $exception->getMessage());
        }

        $this->assertSame(['video', $stored], [$media->fresh()?->type(), $this->storedFiles()]);
        Event::assertDispatched(IngestRejected::class, fn (IngestRejected $event) => $event->reason === RejectionReason::TypeChanged);

        $media->replaceOriginal($this->upload('media/image.jpg', 'poster.jpg'), allowTypeChange: true);

        $this->assertSame(['image', 'image/jpeg'], [$media->fresh()?->type(), $media->original()?->mime()]);
        $this->assertStringStartsWith('image/'.$media->uid.'/original-', (string) $media->original()?->path());
        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id && $job->pipeline === null);
    }

    #[Test]
    public function a_run_in_progress_is_canceled_through_its_transcoder_without_failing_the_media(): void
    {
        Event::fake([MediaFailed::class, PipelineCanceled::class]);
        $this->transcoder->hold = true;
        $media = $this->video();
        $run = PipelineRun::query()->whereMorphedTo('pipelinable', $media)->with('pipelineJobs')->sole();
        $this->assertSame(PipelineStatus::Pending, $run->status());

        Mediatheque::replaceOriginal($media, $this->upload('media/test.mp4', 'new cut.mp4', 'new cut'));

        $run = $run->fresh(['pipelineJobs']);
        $this->assertNotNull($run);
        $this->assertSame(PipelineStatus::Canceled, $run->status());
        $this->assertNotNull($run->endedAt());
        foreach ($run->jobs() as $job) {
            $this->assertSame([JobStatus::Canceled, OriginalReplacer::CANCELED_REASON], [$job->status(), $job->reason()]);
        }
        $this->assertSame([$run->key()], $this->transcoder->canceled, 'Its transcoder is asked to stop it.');
        $this->assertSame(MediaStatus::Pending, $media->fresh()?->status());
        Event::assertDispatched(PipelineCanceled::class, fn (PipelineCanceled $event) => $event->run->key() === $run->key());
        Event::assertNotDispatched(MediaFailed::class);

        $job = $run->job('h264');
        $this->assertNotNull($job);
        app(Recorder::class)->started($job);
        app(Recorder::class)->failed($job, TranscoderError::dependencyFailed('original'));

        $this->assertSame(JobStatus::Canceled, $run->fresh(['pipelineJobs'])?->job('h264')?->status(), 'Late reports change nothing.');
        $this->assertSame(MediaStatus::Pending, Media::query()->findOrFail($media->id)->status());
    }

    #[Test]
    public function a_probe_in_progress_is_canceled_and_the_media_probed_again(): void
    {
        Event::fake([MediaFailed::class]);
        $media = Mediatheque::ingest($this->upload('media/test.mp4', 'clip.mp4'));
        $this->assertInstanceOf(Media::class, $media);
        $probe = new PipelineRun;
        $probe->forceFill([
            'name' => 'probe',
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->id,
            'transcoder' => 'prober',
            'external_id' => 'job-42',
            'definition' => ['transcoder' => 'prober', 'renditions' => ['probe' => ['op' => 'probe']], 'main' => null, 'then' => 'video'],
            'output_source' => 'public',
            'path_prefix' => 'video/'.$media->uid.'/probe',
            'visibility' => Visibility::Public,
            'status' => PipelineStatus::Running,
        ])->save();
        (new PipelineJob)->forceFill(['pipeline_id' => $probe->id, 'name' => 'probe', 'status' => JobStatus::Running])->save();

        Mediatheque::replaceOriginal($media, $this->upload('media/test.mp4', 'new cut.mp4', 'new cut'));

        $this->assertSame(PipelineStatus::Canceled, $probe->fresh()?->status());
        $this->assertSame(MediaStatus::Pending, $media->fresh()?->status());
        Event::assertNotDispatched(MediaFailed::class);
        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id && $job->pipeline === 'video');
    }

    #[Test]
    public function a_probe_reading_the_previous_original_records_nothing_on_the_new_one(): void
    {
        $adapter = new class extends InMemoryFilesystemAdapter
        {
            /** @var (Closure(): void)|null */
            public ?Closure $whileReading = null;

            public function readStream(string $path)
            {
                $hook = $this->whileReading;
                $this->whileReading = null;
                if ($hook !== null) {
                    $hook();
                }

                return parent::readStream($path);
            }
        };
        Storage::extend('reading', fn () => new FilesystemAdapter(new Filesystem($adapter), $adapter));
        config(['filesystems.disks.uploads' => ['driver' => 'reading'], 'mediatheque.source' => 'uploads']);
        Storage::forgetDisk('uploads');
        Storage::disk('uploads')->put('incoming/clip', (string) file_get_contents(self::fixturePath('media/test.mp4')));
        $media = Mediatheque::ingest(Source::disk('uploads', 'incoming/clip', 'clip.mp4'));
        $this->assertInstanceOf(Media::class, $media);
        $this->assertNull($media->original()?->checksum());
        $adapter->whileReading = function () use ($media) {
            Mediatheque::replaceOriginal($media, $this->upload('media/test.mp4', 'new cut.mp4', 'new cut'));
        };

        app()->call([new ProbeMedia($media->id), 'handle']);

        $fresh = Media::query()->findOrFail($media->id);
        $checksum = hash('sha256', file_get_contents(self::fixturePath('media/test.mp4')).'new cut');
        $this->assertSame([$checksum, 'new cut.mp4', MediaStatus::Pending], [$fresh->metadata('checksum'), $fresh->metadata('filename'), $fresh->status()]);
        $this->assertSame($checksum, $fresh->original()?->checksum());
        $this->assertSame(PipelineStatus::Canceled, PipelineRun::query()->where('name', 'probe')->sole()->status());
    }

    #[Test]
    public function the_options_choose_the_name_the_pipeline_and_the_disk(): void
    {
        $this->useMemoryDisk('archive');
        $media = $this->video();

        Mediatheque::replaceOriginal($media, $this->upload('media/test.mp4', 'new cut.mp4', 'new cut'), new IngestOptions(
            visibility: Visibility::Private,
            pipeline: 'audio',
            name: 'Episode 1',
            source: 'archive',
            data: ['ignored' => true],
        ));

        $this->assertSame(['Episode 1', Visibility::Public, null], [$media->name(), $media->visibility(), $media->data], 'Only the name, the pipeline and the source are read.');
        $original = $media->original();
        $this->assertNotNull($original);
        $this->assertSame('archive', $original->source());
        Storage::disk('archive')->assertExists((string) $original->path());
        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id && $job->pipeline === 'audio');
    }

    #[Test]
    public function the_application_attributes_and_visibility_are_kept(): void
    {
        Schema::table('mediatheque_medias', fn (Blueprint $table) => $table->unsignedBigInteger('space_id')->nullable());
        config(['mediatheque.file_path_format' => 'spaces/{media.space_id}/{media.uid}/{handle}{index}.{extension}']);
        $media = Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'), visibility: Visibility::Private, attributes: ['space_id' => 7], data: ['credit' => 'Me']);
        $this->assertInstanceOf(Media::class, $media);

        $media->replaceOriginal($this->upload('media/image.jpg', 'photo.jpg', 'retouched'));

        $fresh = Media::query()->findOrFail($media->id);
        $this->assertSame([7, Visibility::Private, ['credit' => 'Me']], [$fresh->getAttribute('space_id'), $fresh->visibility(), $fresh->data]);
        $this->assertStringStartsWith('spaces/7/'.$media->uid.'/original-', (string) $media->original()?->path());
        $this->assertSame('private', Storage::disk('public')->getVisibility((string) $media->original()?->path()));
    }

    #[Test]
    public function replacing_twice_with_the_same_upload_stores_one_file_and_probes_once(): void
    {
        Event::fake([OriginalReplaced::class]);
        $media = Mediatheque::ingest($this->upload('media/image.jpg', 'photo.jpg'));

        Mediatheque::replaceOriginal($media, $this->upload('media/image.jpg', 'photo.jpg', 'retouched'));
        $stored = $this->storedFiles();
        Mediatheque::replaceOriginal($media, $this->upload('media/image.jpg', 'photo.jpg', 'retouched'));

        $this->assertSame($stored, $this->storedFiles());
        $this->assertCount(2, $stored, 'The previous original waits for its deletion job.');
        $this->assertSame(1, File::query()->count());
        Event::assertDispatchedTimes(OriginalReplaced::class, 1);
        Bus::assertDispatchedTimes(ProbeMedia::class, 2);
        Bus::assertDispatchedTimes(DeleteStoredFiles::class, 1);
        $this->assertSame([], glob(sys_get_temp_dir().'/mediatheque/*') ?: [], 'No temporary file is left.');
    }

    #[Test]
    public function the_new_file_is_deleted_when_saving_fails(): void
    {
        $media = $this->video();
        $files = File::query()->orderBy('id')->get()->toArray();
        $stored = $this->storedFiles();
        File::creating(fn () => throw new RuntimeException('The database is gone.'));

        try {
            Mediatheque::replaceOriginal($media, $this->upload('media/test.mp4', 'new cut.mp4', 'new cut'));
            $this->fail('Saving did not fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The database is gone.', $exception->getMessage());
        }

        $this->assertSame($stored, $this->storedFiles());
        $this->assertSame($files, File::query()->orderBy('id')->get()->toArray());
        $this->assertSame(MediaStatus::Ready, $media->fresh()?->status());
        $this->assertSame(PipelineStatus::Completed, PipelineRun::query()->sole()->status());
        Bus::assertNotDispatched(DeleteStoredFiles::class);
    }

    #[Test]
    public function the_pipeline_produces_the_renditions_again_once_the_new_original_is_probed(): void
    {
        $media = $this->video();
        $before = $media->files()->map(fn (FileContract $file) => $file->path())->all();

        Mediatheque::replaceOriginal($media, $this->upload('media/test.mp4', 'new cut.mp4', 'new cut'));
        app(ProbeResults::class)->done($media, ['kind' => 'video', 'width' => 320], 'video');

        $media = $media->fresh() ?? $this->fail('The media is gone.');
        $this->assertSame(MediaStatus::Ready, $media->status());
        $this->assertSame(['h264', 'original', 'poster', 'thumbnails'], $media->files()->map(fn (FileContract $file) => $file->handle())->all());
        $this->assertSame([], array_intersect($before, $media->files()->map(fn (FileContract $file) => $file->path())->all()));
        $this->assertCount(2, PipelineRun::query()->where('name', 'video')->get());
    }
}
