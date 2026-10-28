<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Contracts\Mediatheque as MediathequeContract;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\Transcoder;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Facades\Mediatheque;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\MediathequeManager;
use Folklore\Mediatheque\Models\Media;
use Folklore\Mediatheque\Models\PipelineJob;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\OutputFile;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Probe\ProbeOutcome;
use Folklore\Mediatheque\Tests\Concerns\CreatesMedia;
use Folklore\Mediatheque\Tests\Contracts\ChangesVisibilityContractTests;
use Folklore\Mediatheque\Tests\Contracts\ReplacesOriginalsContractTests;
use Folklore\Mediatheque\Tests\Contracts\ResolvesReferencesContractTests;
use Folklore\Mediatheque\Tests\Contracts\RunActionsContractTests;
use Folklore\Mediatheque\Tests\DatabaseTestCase;
use Folklore\Mediatheque\Tests\Support\ScriptedTranscoder;
use Folklore\Mediatheque\Transcoders\Capabilities;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use PHPUnit\Framework\Attributes\Test;

class MediathequeManagerTest extends DatabaseTestCase
{
    use ChangesVisibilityContractTests;
    use CreatesMedia;
    use ReplacesOriginalsContractTests;
    use ResolvesReferencesContractTests;
    use RunActionsContractTests;

    private ScriptedTranscoder $transcoder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->transcoder = new ScriptedTranscoder(app(Recorder::class));
        app(TranscoderManager::class)->extend('scripted', fn () => $this->transcoder);
        config(['mediatheque.pipelines.video.transcoder' => 'scripted']);
    }

    protected function mediatheque(): MediathequeContract
    {
        return app(MediathequeContract::class);
    }

    protected function probedVideo(): MediaContract
    {
        $media = $this->createMedia(['status' => MediaStatus::Processing, 'metadata' => ['kind' => 'video']]);
        $this->createFile($media, 'original', ['mime' => 'video/mp4']);

        return $media;
    }

    protected function videoWithStoredFiles(): MediaContract
    {
        $media = $this->createMedia(['status' => MediaStatus::Ready, 'metadata' => ['kind' => 'video']]);
        foreach (['original' => 'video/mp4', 'h264' => 'video/mp4'] as $handle => $mime) {
            $file = $this->createFile($media, $handle, ['mime' => $mime]);
            Storage::disk('public')->put((string) $file->path, $handle, 'public');
        }

        return $media;
    }

    protected function completeHeldRun(PipelineRunContract $run): void
    {
        foreach ($this->freshRun($run)->jobs() as $job) {
            $path = $run->pathPrefix().'/'.$job->rendition()->name.'.bin';
            Storage::disk($run->outputSource())->put($path, $job->rendition()->name, $run->visibility()->value);
            app(Recorder::class)->completed($job, Outputs::file(new OutputFile($path, mime: 'application/octet-stream')));
        }
    }

    protected function videoWhoseProbeFailed(): MediaContract
    {
        $media = $this->createMedia(['status' => MediaStatus::Failed, 'metadata' => ['filename' => 'clip.mp4']]);
        $this->createFile($media, 'original', ['mime' => 'video/mp4', 'checksum' => hash('sha256', 'clip')]);

        return $media;
    }

    protected function failRenditions(array $renditions): void
    {
        $this->transcoder->failures = $renditions;
    }

    protected function holdRuns(): void
    {
        $this->transcoder->hold = true;
    }

    protected function probesSucceed(): void
    {
        app()->instance(Prober::class, new class implements Prober
        {
            public function probe(FileContract $file): ProbeOutcome
            {
                return ProbeOutcome::done(['kind' => 'video']);
            }
        });
    }

    protected function freshRun(PipelineRunContract $run): PipelineRunContract
    {
        return PipelineRun::query()->where('uid', $run->key())->with('pipelineJobs')->sole();
    }

    protected function freshMedia(MediaContract $media): MediaContract
    {
        return Media::query()->where('uid', $media->uid())->sole();
    }

    protected function videoFile(string $variant): UploadedFile|Source|string
    {
        $path = tempnam(sys_get_temp_dir(), 'replace');
        file_put_contents((string) $path, file_get_contents(self::fixturePath('media/test.mp4')).$variant);

        return Source::path((string) $path, 'new-cut.mp4');
    }

    protected function imageFile(): UploadedFile|Source|string
    {
        return self::fixturePath('media/image.jpg');
    }

    protected function unknownFile(): UploadedFile|Source|string
    {
        $path = tempnam(sys_get_temp_dir(), 'replace');
        file_put_contents((string) $path, 'Just some text');

        return (string) $path;
    }

    protected function runsOf(MediaContract $media, string $pipeline): array
    {
        $this->assertInstanceOf(Media::class, $media);

        return array_values(PipelineRun::query()->whereMorphedTo('pipelinable', $media)->where('name', $pipeline)->with('pipelineJobs')->orderBy('id')->get()->all());
    }

    protected function internalId(MediaContract $media): int
    {
        return $media instanceof Media ? $media->id : 0;
    }

    #[Test]
    public function the_facade_resolves_the_manager_outside_the_fake(): void
    {
        $this->assertInstanceOf(MediathequeManager::class, Mediatheque::getFacadeRoot());
    }

    #[Test]
    public function a_deleted_media_does_not_resolve(): void
    {
        $media = $this->createMedia();
        $media->delete();

        $this->assertNull(Mediatheque::resolve($media->uid));
        $this->assertTrue(Mediatheque::resolveMany(['media://'.$media->uid])->isEmpty());
    }

    #[Test]
    public function resolve_many_runs_one_query_per_form_with_the_files(): void
    {
        $first = $this->createMedia();
        $second = $this->createMedia();
        $this->createFile($first);
        $this->createFile($second);

        DB::enableQueryLog();
        $resolved = Mediatheque::resolveMany([$first->uid, 'media://'.$second->uid, 'medias://'.$first->id]);
        $resolved->each(fn (MediaContract $media) => $media->original());
        $queries = count(DB::getQueryLog());

        $this->assertSame([$first->uid, 'media://'.$second->uid, 'medias://'.$first->id], $resolved->keys()->all());
        $this->assertSame(4, $queries, 'Media by uid and by id, and their files for each.');
    }

    #[Test]
    public function refresh_asks_a_transcoder_that_can_refresh(): void
    {
        $this->holdRuns();
        $run = Mediatheque::runPipeline($this->probedVideo(), 'video');
        $this->assertNotNull($run);

        $this->assertTrue(Mediatheque::refresh($run));
        $this->assertSame([$run->key()], $this->transcoder->refreshed);
    }

    #[Test]
    public function a_transcoder_without_the_capability_is_not_asked(): void
    {
        $this->holdRuns();
        app(TranscoderManager::class)->extend('basic', fn () => new class($this->transcoder) implements Transcoder
        {
            public function __construct(private readonly ScriptedTranscoder $scripted) {}

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
                return $this->scripted->submit($run);
            }

            public function cancel(PipelineRunContract $run): void
            {
                throw new LogicException('Not asked.');
            }

            public function refresh(PipelineRunContract $run): void
            {
                throw new LogicException('Not asked.');
            }
        });
        config(['mediatheque.pipelines.video.transcoder' => 'basic']);
        $run = Mediatheque::runPipeline($this->probedVideo(), 'video');
        $this->assertNotNull($run);

        $this->assertFalse(Mediatheque::cancel($run));
        $this->assertFalse(Mediatheque::refresh($run));
        $this->assertSame(PipelineStatus::Pending, $this->freshRun($run)->status());
    }

    #[Test]
    public function retry_leaves_out_renditions_the_pipeline_does_not_have_any_more(): void
    {
        $media = $this->probedVideo();
        $this->failRenditions(['poster', 'thumbnails']);
        $failed = Mediatheque::runPipeline($media, 'video');
        $this->assertNotNull($failed);
        $this->failRenditions([]);
        config(['mediatheque.pipelines.video.renditions' => [
            'h264' => ['op' => 'video.transcode', 'codec' => 'h264'],
            'poster' => ['op' => 'video.poster', 'role' => 'poster'],
        ]]);

        $retry = Mediatheque::retry($failed);

        $this->assertSame(['poster'], array_keys((array) $retry?->renditions()));
    }

    #[Test]
    public function a_probe_in_the_queue_is_canceled_through_the_recorder_and_fails_the_media(): void
    {
        $media = $this->createMedia(['status' => MediaStatus::Pending]);
        $this->createFile($media, 'original', ['mime' => 'video/mp4']);
        $this->stuckProbe($media, externalId: null);
        $pending = $this->createMedia(['status' => MediaStatus::Pending]);
        $this->createFile($pending, 'original', ['mime' => 'video/mp4']);
        $this->stuckProbe($pending, externalId: 'job-42');

        $this->assertTrue(Mediatheque::cancel(PipelineRun::query()->whereMorphedTo('pipelinable', $media)->sole()));
        $this->assertFalse(Mediatheque::cancel(PipelineRun::query()->whereMorphedTo('pipelinable', $pending)->sole()), 'Its driver has it.');

        $this->assertSame(MediaStatus::Failed, $this->freshMedia($media)->status());
        $this->assertSame(PipelineStatus::Canceled, PipelineRun::query()->whereMorphedTo('pipelinable', $media)->sole()->status());
        $this->assertSame(MediaStatus::Pending, $this->freshMedia($pending)->status());
    }

    #[Test]
    public function probe_again_queues_the_probe_with_the_pipeline_ingest_chose(): void
    {
        Bus::fake([ProbeMedia::class]);
        $media = $this->videoWhoseProbeFailed();
        $this->assertInstanceOf(Media::class, $media);
        $this->stuckProbe($media, externalId: null, then: 'clip');
        PipelineRun::query()->update(['status' => PipelineStatus::Failed]);

        $this->assertTrue(Mediatheque::probeAgain($media));

        $this->assertSame(MediaStatus::Pending, $this->freshMedia($media)->status());
        Bus::assertDispatched(ProbeMedia::class, fn (ProbeMedia $job) => $job->mediaId === $media->id && $job->pipeline === 'clip');
    }

    /**
     * A probe run that started and never ended.
     */
    private function stuckProbe(Media $media, ?string $externalId, ?string $then = null): PipelineRun
    {
        $run = new PipelineRun;
        $run->forceFill([
            'name' => 'probe',
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->id,
            'transcoder' => 'prober',
            'external_id' => $externalId,
            'definition' => ['transcoder' => 'prober', 'renditions' => ['probe' => ['op' => 'probe']], 'main' => null, 'then' => $then],
            'output_source' => 'public',
            'path_prefix' => 'video/'.$media->uid.'/probe',
            'visibility' => Visibility::Public,
            'status' => PipelineStatus::Running,
        ])->save();
        (new PipelineJob)->forceFill(['pipeline_id' => $run->id, 'name' => 'probe', 'status' => JobStatus::Running])->save();

        return $run;
    }
}
