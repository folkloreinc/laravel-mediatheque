<?php

namespace Folklore\Mediatheque\Testing;

use Carbon\CarbonImmutable;
use Closure;
use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Mediatheque;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\IngestRejected;
use Folklore\Mediatheque\Events\MediaCreated;
use Folklore\Mediatheque\Events\MediaFailed;
use Folklore\Mediatheque\Events\MediaProbed;
use Folklore\Mediatheque\Events\MediaReady;
use Folklore\Mediatheque\Events\MediaVisibilityChanged;
use Folklore\Mediatheque\Events\OriginalReplaced;
use Folklore\Mediatheque\Events\PipelineCanceled;
use Folklore\Mediatheque\Events\RenditionCanceled;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;
use Folklore\Mediatheque\Ingest\SourceKind;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\References\MediaReference;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Testing\Models\FakeFile;
use Folklore\Mediatheque\Testing\Models\FakeMedia;
use Folklore\Mediatheque\Testing\Models\FakePipelineRun;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\File as LocalFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Testing\Fakes\Fake;
use InvalidArgumentException;
use PHPUnit\Framework\Assert as PHPUnit;
use RuntimeException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Mediatheque::fake(): ingest, probe and pipelines in memory, without
 * ffmpeg, a database or a queue worker.
 *
 * - Files are stored on fake disks (Storage::fake()).
 * - Types come from the mime the upload reports, the probe returns
 *   fixtures, and renditions complete at once with placeholder files,
 *   through the same recorder rules as the package.
 * - Events are dispatched as the package dispatches them.
 */
class MediathequeFake implements Fake, Mediatheque
{
    /** @var list<FakeMedia> */
    private array $media = [];

    /** @var list<FakePipelineRun> */
    private array $runs = [];

    /** @var array<string, true> */
    private array $fakedDisks = [];

    private readonly FakeUrlGenerator $urls;

    private readonly InMemoryRecorder $recorder;

    private readonly FakeTranscoder $transcoder;

    private readonly FakeProber $prober;

    private readonly FakeIngestor $ingestor;

    public function __construct(
        private readonly Config $config,
        private readonly Dispatcher $events,
        private readonly Filesystems $filesystems,
    ) {
        $ttl = $config->get('mediatheque.urls.temporary_ttl', 3600);
        $this->urls = new FakeUrlGenerator($filesystems, is_int($ttl) ? $ttl : 3600);
        $this->recorder = new InMemoryRecorder($events, $this->urls, new MediathequeConfig($config), $filesystems);
        $this->transcoder = new FakeTranscoder($this->recorder, $filesystems);
        $this->prober = new FakeProber;
        $this->ingestor = new FakeIngestor($this);
    }

    public function ingest(
        mixed $source,
        ?Visibility $visibility = null,
        ?string $pipeline = null,
        ?string $name = null,
        array $data = [],
        array $attributes = [],
    ): Media {
        return $this->ingestSource(Source::from($source), new IngestOptions(
            visibility: $visibility,
            pipeline: $pipeline,
            name: $name,
            data: $data,
            attributes: $attributes,
        ));
    }

    /**
     * The ingest step of the fake. A file whose mime matches no type is
     * rejected, as the package does, with `IngestRejected`.
     */
    public function ingestSource(Source $source, IngestOptions $options): Media
    {
        try {
            [$localPath, $mime] = $this->read($source);
            $type = $this->detectType($mime) ?? throw FileRejected::because(
                RejectionReason::UnknownType,
                sprintf('The file "%s" (%s) matches no media type.', $source->filename ?? 'file', $mime),
            );
        } catch (FileRejected $exception) {
            $this->events->dispatch(new IngestRejected($exception->reason, $exception->getMessage(), $source->filename));

            throw $exception;
        }

        $visibility = $options->visibility ?? Visibility::tryFrom($this->string('mediatheque.ingest.visibility', 'public')) ?? Visibility::Public;
        $storage = $options->source ?? $this->string('mediatheque.source', 'public');
        $pipeline = $options->pipeline ?? $this->pipelineOf($type);

        $media = new FakeMedia(
            id: count($this->media) + 1,
            uid: Uid::generate($this->string('mediatheque.ids.media_prefix', 'med')),
            type: $type,
            name: $options->name ?? $source->filename,
            visibility: $visibility,
            data: $options->data,
            attributes: $options->attributes,
            mainRendition: $pipeline !== null ? $this->pipeline($pipeline)->mainRendition()?->name : null,
        );

        $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
        $path = $media->uid.'/original.'.$extension;
        $this->disk($storage)->put($path, (string) file_get_contents($localPath));
        $size = (int) filesize($localPath);
        $checksum = (string) hash_file('sha256', $localPath);

        $media->attach(new FakeFile(
            media: $media,
            urls: $this->urls,
            handle: File::ORIGINAL,
            source: $storage,
            path: $path,
            role: 'original',
            mime: $mime,
            size: $size,
            checksum: $checksum,
            name: $source->filename,
        ));
        $media->metadata = [
            MetadataKey::Filename->value => $source->filename,
            MetadataKey::Mime->value => $mime,
            MetadataKey::Extension->value => $extension,
            MetadataKey::Size->value => $size,
            MetadataKey::Checksum->value => $checksum,
        ];

        $this->media[] = $media;
        $this->events->dispatch(new MediaCreated($media));

        $this->probe($media, $pipeline);

        return $media;
    }

    /**
     * The fake replaces the original as the package does
     * (docs/decisions/0019), at once: the previous files are deleted from
     * the fake disks, the runs that haven't ended are canceled, and the new
     * original is probed, then its pipeline runs.
     */
    public function replaceOriginal(Media $media, mixed $source, ?IngestOptions $options = null, bool $allowTypeChange = false): Media
    {
        $media = $this->find($media);
        $source = Source::from($source);
        $options ??= new IngestOptions;

        try {
            [$localPath, $mime] = $this->read($source);
            $type = $this->detectType($mime) ?? throw FileRejected::because(
                RejectionReason::UnknownType,
                sprintf('The file "%s" (%s) matches no media type.', $source->filename ?? 'file', $mime),
            );
            if ($type !== $media->type && ! $allowTypeChange) {
                throw FileRejected::because(
                    RejectionReason::TypeChanged,
                    sprintf('The file "%s" is a %s, and %s is a %s: its type can\'t change.', $source->filename ?? 'file', $type, $media->uid, $media->type),
                );
            }
        } catch (FileRejected $exception) {
            $this->events->dispatch(new IngestRejected($exception->reason, $exception->getMessage(), $source->filename));

            throw $exception;
        }

        $checksum = (string) hash_file('sha256', $localPath);
        $previous = $media->original();
        if ($options->name !== null) {
            $media->name = $options->name;
        }
        if ($previous !== null && $previous->checksum() === $checksum) {
            return $media;
        }

        foreach ($this->runs as $run) {
            if ($run->media === $media && ! $run->status->isFinal()) {
                $this->cancelReplaced($run);
            }
        }

        $storage = $options->source ?? $previous?->source() ?? $this->string('mediatheque.source', 'public');
        $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
        $path = $media->uid.'/original-'.substr($checksum, 0, 16).'.'.$extension;
        $this->disk($storage)->put($path, (string) file_get_contents($localPath));
        $size = (int) filesize($localPath);

        foreach ($media->files as $file) {
            if ($file->source !== null && $file->path !== null && ($file->source !== $storage || $file->path !== $path)) {
                $this->disk($file->source)->delete($file->path);
            }
        }

        $pipeline = $options->pipeline ?? $this->pipelineOf($type);
        $media->files = [];
        $media->attach(new FakeFile(
            media: $media,
            urls: $this->urls,
            handle: File::ORIGINAL,
            source: $storage,
            path: $path,
            role: 'original',
            mime: $mime,
            size: $size,
            checksum: $checksum,
            name: $source->filename,
        ));
        $media->type = $type;
        $media->mainRendition = $pipeline !== null ? $this->pipeline($pipeline)->mainRendition()?->name : null;
        $media->status = MediaStatus::Pending;
        $media->metadata = [
            MetadataKey::Filename->value => $source->filename,
            MetadataKey::Mime->value => $mime,
            MetadataKey::Extension->value => $extension,
            MetadataKey::Size->value => $size,
            MetadataKey::Checksum->value => $checksum,
        ];

        $this->events->dispatch(new OriginalReplaced($media, $previous?->checksum(), $checksum));

        $this->probe($media, $pipeline);

        return $media;
    }

    /**
     * The fake applies the visibility to the files on their fake disks at
     * once, and to the files its runs record later.
     */
    public function setVisibility(Media $media, Visibility $visibility): bool
    {
        $media = $this->find($media);
        $previous = $media->visibility;

        if ($previous === $visibility) {
            return false;
        }

        $media->visibility = $visibility;
        foreach ($media->files as $file) {
            $this->recorder->applyVisibility($file);
        }

        $this->events->dispatch(new MediaVisibilityChanged($media, $previous, $visibility));

        return true;
    }

    public function resolve(MediaReference|string $reference): ?Media
    {
        if (is_string($reference)) {
            $reference = Uid::isValid($reference) ? MediaReference::uid($reference) : MediaReference::tryParse($reference);
        }

        if ($reference === null) {
            return null;
        }

        foreach ($this->media as $media) {
            if ($reference->isLegacy() ? $media->id === $reference->id : $media->uid === $reference->uid) {
                return $media;
            }
        }

        return null;
    }

    public function resolveMany(iterable $references): Collection
    {
        $resolved = [];

        foreach ($references as $reference) {
            $media = $this->resolve($reference);
            if ($media !== null) {
                $resolved[(string) $reference] = $media;
            }
        }

        return new Collection($resolved);
    }

    public function runPipeline(Media $media, ?string $pipeline = null, ?array $renditions = null, bool $force = false): ?PipelineRun
    {
        $media = $this->find($media);
        $definition = $this->pipeline($pipeline ?? $this->pipelineOf($media->type) ?? throw new InvalidArgumentException(sprintf('The type "%s" has no pipeline.', $media->type)));

        $names = $renditions ?? array_keys(array_filter($definition->renditions, fn (Rendition $rendition) => $rendition->keep));
        if (! $force) {
            $names = array_values(array_filter($names, fn (string $name) => ! $this->isUpToDate($media, $definition->rendition($name))));
        }

        if ($names === []) {
            return null;
        }

        $key = 'run_'.(count($this->runs) + 1);
        $run = new FakePipelineRun(
            key: $key,
            media: $media,
            pipeline: $definition,
            renditions: $definition->closeUnderFrom($names),
            outputSource: $definition->source ?? $media->original()?->source() ?? $this->string('mediatheque.source', 'public'),
            pathPrefix: $media->uid.'/'.$key,
        );
        $this->disk($run->outputSource);
        $this->runs[] = $run;

        $run->externalId = $this->transcoder->submit($run);

        return $run;
    }

    public function retry(PipelineRun $run): ?PipelineRun
    {
        $run = $this->findRun($run);

        if (! $run->status->isFinal() || $this->hasRunInProgress($run->media, $run->name())) {
            return null;
        }

        $pipeline = $this->pipeline($run->name());
        $produced = [];
        foreach (array_slice($this->runs, (int) array_search($run, $this->runs, true) + 1) as $later) {
            if ($later->media === $run->media && $later->name() === $run->name()) {
                foreach ($later->jobs as $name => $job) {
                    if ($job->status() === JobStatus::Completed) {
                        $produced[] = $name;
                    }
                }
            }
        }

        $renditions = [];
        foreach ($run->jobs as $name => $job) {
            if (in_array($job->status(), [JobStatus::Failed, JobStatus::Canceled], true) && $pipeline->rendition($name) !== null && ! in_array($name, $produced, true)) {
                $renditions[] = $name;
            }
        }

        return $renditions === [] ? null : $this->runPipeline($run->media, $run->name(), $renditions, force: true);
    }

    public function cancel(PipelineRun $run): bool
    {
        $run = $this->findRun($run);

        if ($run->status->isFinal() || ! $this->transcoder->capabilities()->cancel) {
            return false;
        }

        $this->transcoder->cancel($run);

        return true;
    }

    public function refresh(PipelineRun $run): bool
    {
        $run = $this->findRun($run);

        if ($run->status->isFinal() || ! $this->transcoder->capabilities()->refresh) {
            return false;
        }

        $this->transcoder->refresh($run);

        return true;
    }

    /**
     * The fake probes again at once, with what probeWith() and failProbe()
     * set.
     */
    public function probeAgain(Media $media): bool
    {
        $media = $this->find($media);

        if ($media->status !== MediaStatus::Failed || $media->metadata(MetadataKey::Kind->value) !== null) {
            return false;
        }

        $media->status = MediaStatus::Pending;
        $this->probe($media, $this->pipelineOf($media->type));

        return true;
    }

    /**
     * Make a rendition fail, with this error code.
     */
    public function failRendition(string $rendition, TranscoderErrorCode $code = TranscoderErrorCode::OperationFailed, string $message = 'Failed by Mediatheque::fake().'): static
    {
        $this->transcoder->failures[$rendition] = new TranscoderError($code, $message);

        return $this;
    }

    /**
     * Make a rendition skip, as if its condition didn't match.
     */
    public function skipRendition(string $rendition, string $reason = 'Skipped by Mediatheque::fake().'): static
    {
        $this->transcoder->skips[$rendition] = $reason;

        return $this;
    }

    /**
     * Keep the renditions of new runs queued, as if no worker ran them, until
     * releasePipelines().
     */
    public function holdPipelines(): static
    {
        $this->transcoder->hold = true;

        return $this;
    }

    /**
     * Report the progress of a rendition of the media's latest run that has
     * it, as a driver does, through the recorder: RenditionProgressed is
     * dispatched when it passes a step. With holdPipelines(), so the
     * rendition doesn't complete at once.
     */
    public function progressRendition(Media $media, string $rendition, float $progress): static
    {
        foreach (array_reverse($this->runs) as $run) {
            if ($run->media->uid === $media->uid() && isset($run->jobs[$rendition])) {
                $this->recorder->progress($run->jobs[$rendition], $progress);

                return $this;
            }
        }

        throw new InvalidArgumentException(sprintf('No run of %s has the rendition "%s".', $media->uid(), $rendition));
    }

    /**
     * Run the renditions that are still queued.
     */
    public function releasePipelines(): static
    {
        $this->transcoder->hold = false;

        foreach ($this->runs as $run) {
            if (! $run->status->isFinal()) {
                $this->transcoder->submit($run);
            }
        }

        return $this;
    }

    /**
     * Metadata the probe returns, over the fixtures: an array, or a closure
     * that receives the original.
     *
     * @param  (Closure(File): array<string, mixed>)|array<string, mixed>  $metadata
     */
    public function probeWith(Closure|array $metadata): static
    {
        $this->prober->metadata = $metadata;

        return $this;
    }

    public function failProbe(TranscoderErrorCode $code = TranscoderErrorCode::InputUnreadable, string $message = 'Failed by Mediatheque::fake().'): static
    {
        $this->prober->failure = new TranscoderError($code, $message);

        return $this;
    }

    /**
     * Assert that a media was ingested, matching the callback when given.
     *
     * @param  (Closure(Media): bool)|null  $callback
     */
    public function assertIngested(?Closure $callback = null, ?int $times = null): void
    {
        $count = $this->ingested($callback)->count();

        if ($times !== null) {
            PHPUnit::assertSame($times, $count, sprintf('Expected %d matching media to be ingested, got %d.', $times, $count));

            return;
        }

        PHPUnit::assertGreaterThan(0, $count, 'No matching media was ingested.');
    }

    /**
     * @param  (Closure(Media): bool)|null  $callback
     */
    public function assertNotIngested(?Closure $callback = null): void
    {
        $count = $this->ingested($callback)->count();

        PHPUnit::assertSame(0, $count, sprintf('%d matching media were ingested.', $count));
    }

    public function assertNothingIngested(): void
    {
        $this->assertNotIngested();
    }

    /**
     * Assert that a pipeline ran on a media: its type's pipeline, or the one
     * named.
     */
    public function assertPipelineRan(Media $media, ?string $pipeline = null): void
    {
        PHPUnit::assertNotEmpty(
            $this->runs($media, $pipeline),
            sprintf('The pipeline "%s" did not run on %s.', $pipeline ?? $media->type(), $media->uid()),
        );
    }

    public function assertPipelineNotRan(Media $media, ?string $pipeline = null): void
    {
        PHPUnit::assertEmpty(
            $this->runs($media, $pipeline),
            sprintf('The pipeline "%s" ran on %s.', $pipeline ?? $media->type(), $media->uid()),
        );
    }

    /**
     * Assert the status of a rendition in the latest run that produced it.
     */
    public function assertRendition(Media $media, string $rendition, JobStatus $status): void
    {
        $job = null;
        foreach (array_reverse($this->runs) as $run) {
            if ($run->media->uid === $media->uid() && isset($run->jobs[$rendition])) {
                $job = $run->jobs[$rendition];
                break;
            }
        }

        PHPUnit::assertNotNull($job, sprintf('No run of %s had the rendition "%s".', $media->uid(), $rendition));
        PHPUnit::assertSame($status, $job->status, sprintf('The rendition "%s" of %s is %s, not %s.', $rendition, $media->uid(), $job->status->value, $status->value));
    }

    /**
     * The ingested media, matching the callback when given.
     *
     * @param  (Closure(Media): bool)|null  $callback
     * @return Collection<int, Media>
     */
    public function ingested(?Closure $callback = null): Collection
    {
        $media = array_filter($this->media, fn (FakeMedia $media) => $callback === null || $callback($media));

        return self::collectMedia(array_values($media));
    }

    /**
     * The pipeline runs, of a media and a pipeline when given.
     *
     * @return Collection<int, PipelineRun>
     */
    public function runs(?Media $media = null, ?string $pipeline = null): Collection
    {
        $runs = array_filter($this->runs, fn (FakePipelineRun $run) => ($media === null || $run->media->uid === $media->uid())
            && ($pipeline === null || $run->name() === $pipeline));

        return self::collectRuns(array_values($runs));
    }

    public function ingestor(): Ingestor
    {
        return $this->ingestor;
    }

    public function recorder(): Recorder
    {
        return $this->recorder;
    }

    public function transcoder(): FakeTranscoder
    {
        return $this->transcoder;
    }

    public function prober(): Prober
    {
        return $this->prober;
    }

    public function urls(): UrlGenerator
    {
        return $this->urls;
    }

    /**
     * A run of a pipeline on a media, with its jobs queued and not submitted:
     * for tests of transcoders and of the recorder.
     *
     * @param  list<string>|null  $renditions
     */
    public function createRun(Media $media, PipelineDefinition $pipeline, ?array $renditions = null): FakePipelineRun
    {
        $media = $this->find($media);
        $key = 'run_'.(count($this->runs) + 1);
        $run = new FakePipelineRun(
            key: $key,
            media: $media,
            pipeline: $pipeline,
            renditions: $pipeline->closeUnderFrom($renditions ?? array_keys($pipeline->renditions)),
            outputSource: $pipeline->source ?? $media->original()?->source() ?? $this->string('mediatheque.source', 'public'),
            pathPrefix: $media->uid.'/'.$key,
        );
        $this->disk($run->outputSource);
        $this->runs[] = $run;

        return $run;
    }

    /**
     * @param  list<Media>  $media
     * @return Collection<int, Media>
     */
    private static function collectMedia(array $media): Collection
    {
        return new Collection($media);
    }

    /**
     * @param  list<PipelineRun>  $runs
     * @return Collection<int, PipelineRun>
     */
    private static function collectRuns(array $runs): Collection
    {
        return new Collection($runs);
    }

    private function probe(FakeMedia $media, ?string $pipeline): void
    {
        $original = $media->original();
        if ($original === null) {
            return;
        }

        $outcome = $this->prober->probe($original);

        if ($outcome->isFailed()) {
            $media->status = MediaStatus::Failed;
            $this->events->dispatch(new MediaFailed($media, $outcome->error));

            return;
        }

        if ($outcome->isPending()) {
            return;
        }

        $media->metadata = [...$media->metadata, ...$outcome->metadata];
        if ($original instanceof FakeFile) {
            $original->width = is_int($outcome->metadata['width'] ?? null) ? $outcome->metadata['width'] : null;
            $original->height = is_int($outcome->metadata['height'] ?? null) ? $outcome->metadata['height'] : null;
            $original->duration = is_float($outcome->metadata['duration'] ?? null) ? $outcome->metadata['duration'] : null;
            $original->bitrate = is_int($outcome->metadata['bitrate'] ?? null) ? $outcome->metadata['bitrate'] : null;
        }

        $media->status = MediaStatus::Processing;
        $this->events->dispatch(new MediaProbed($media, $outcome->metadata));

        if ($pipeline === null || $media->mainRendition === null) {
            $media->status = MediaStatus::Ready;
            $this->events->dispatch(new MediaReady($media));

            if ($pipeline === null) {
                return;
            }
        }

        $this->runPipeline($media, $pipeline);
    }

    /**
     * A local copy of the source, and its mime.
     *
     * @return array{string, string}
     */
    private function read(Source $source): array
    {
        $unreadable = fn () => FileRejected::because(RejectionReason::Unreadable, sprintf('The file "%s" can\'t be read.', $source->filename ?? 'file'));

        switch ($source->kind) {
            case SourceKind::Upload:
                $upload = $source->upload;
                if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
                    throw $unreadable();
                }

                return [$upload->getRealPath() ?: throw $unreadable(), $upload->getMimeType() ?? 'application/octet-stream'];

            case SourceKind::Path:
                $path = (string) $source->path;
                if (! is_file($path)) {
                    throw $unreadable();
                }

                return [$path, (new LocalFile($path))->getMimeType() ?? 'application/octet-stream'];

            case SourceKind::Stream:
                $local = $this->temporaryFile();
                $stream = $source->stream;
                if (! is_resource($stream)) {
                    throw $unreadable();
                }
                file_put_contents($local, $stream);

                return [$local, (new LocalFile($local))->getMimeType() ?? 'application/octet-stream'];

            case SourceKind::Disk:
                $disk = $this->filesystems->disk((string) $source->disk);
                $path = (string) $source->path;
                if (! $disk->exists($path)) {
                    throw $unreadable();
                }
                $local = $this->temporaryFile();
                file_put_contents($local, (string) $disk->get($path));

                return [$local, (new LocalFile($local))->getMimeType() ?? 'application/octet-stream'];

            case SourceKind::Url:
                // The fake downloads nothing: the mime comes from the URL's extension.
                $local = $this->temporaryFile();
                file_put_contents($local, 'placeholder');
                $extension = pathinfo((string) parse_url((string) $source->url, PHP_URL_PATH), PATHINFO_EXTENSION);

                return [$local, MimeTypes::getDefault()->getMimeTypes(strtolower($extension))[0] ?? 'application/octet-stream'];
        }
    }

    /**
     * A run of a media whose original was replaced: its renditions that
     * haven't ended are canceled, and the run, without changing the media.
     */
    private function cancelReplaced(FakePipelineRun $run): void
    {
        $now = CarbonImmutable::instance(Date::now());

        foreach ($run->jobs as $job) {
            if ($job->status->canMoveTo(JobStatus::Canceled)) {
                $job->status = JobStatus::Canceled;
                $job->reason = 'The media\'s original was replaced.';
                $job->endedAt = $now;
                $this->events->dispatch(new RenditionCanceled($job));
            }
        }

        $run->status = PipelineStatus::Canceled;
        $run->endedAt = $now;
        $this->events->dispatch(new PipelineCanceled($run));

        $this->transcoder->cancel($run);
    }

    private function detectType(string $mime): ?string
    {
        $types = $this->config->get('mediatheque.types', []);

        foreach (is_array($types) ? $types : [] as $name => $type) {
            $patterns = is_array($type) ? ($type['mimes'] ?? []) : [];
            if (is_string($name) && is_array($patterns) && Str::is(array_filter($patterns, 'is_string'), $mime)) {
                return $name;
            }
        }

        return null;
    }

    private function pipelineOf(string $type): ?string
    {
        $config = $this->config->get('mediatheque.types.'.$type, []);
        if (is_array($config) && array_key_exists('pipeline', $config)) {
            return is_string($config['pipeline']) ? $config['pipeline'] : null;
        }

        return $this->config->has('mediatheque.pipelines.'.$type) ? $type : null;
    }

    private function pipeline(string $name): PipelineDefinition
    {
        $config = $this->config->get('mediatheque.pipelines.'.$name);
        if (! is_array($config)) {
            throw new InvalidArgumentException(sprintf('The pipeline "%s" is not configured.', $name));
        }

        return PipelineDefinition::fromArray($name, $config);
    }

    private function isUpToDate(FakeMedia $media, ?Rendition $rendition): bool
    {
        if ($rendition === null) {
            return false;
        }

        $file = $media->file($rendition->name);

        return $file instanceof FakeFile && $file->definitionHash === $rendition->definitionHash();
    }

    private function findRun(PipelineRun $run): FakePipelineRun
    {
        foreach ($this->runs as $candidate) {
            if ($candidate->key === $run->key()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(sprintf('The run %s was not created by Mediatheque::fake().', $run->key()));
    }

    private function hasRunInProgress(FakeMedia $media, string $pipeline): bool
    {
        foreach ($this->runs as $run) {
            if ($run->media === $media && $run->name() === $pipeline && ! $run->status->isFinal()) {
                return true;
            }
        }

        return false;
    }

    private function find(Media $media): FakeMedia
    {
        foreach ($this->media as $candidate) {
            if ($candidate->uid === $media->uid()) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(sprintf('The media %s was not ingested through Mediatheque::fake().', $media->uid()));
    }

    private function disk(string $name): Filesystem
    {
        if (! isset($this->fakedDisks[$name])) {
            Storage::fake($name);
            $this->fakedDisks[$name] = true;
        }

        return $this->filesystems->disk($name);
    }

    private function temporaryFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mediatheque-fake-');

        return $path !== false ? $path : throw new RuntimeException('Can\'t create a temporary file.');
    }

    private function string(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
