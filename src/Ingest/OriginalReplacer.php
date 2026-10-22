<?php

namespace Folklore\Mediatheque\Ingest;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Events\OriginalReplaced;
use Folklore\Mediatheque\Events\PipelineCanceled;
use Folklore\Mediatheque\Events\RenditionCanceled;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Jobs\DeleteStoredFiles;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Contracts\Bus\Dispatcher as Jobs;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Throwable;

/**
 * Replaces a media's original (docs/decisions/0019), on the Eloquent models.
 *
 * 1. The file is read and validated as ingest does (MediaIngestor), and a
 *    file of another type is refused unless the type may change. A rejected
 *    file changes nothing.
 * 2. A file that is the original already changes nothing: same checksum,
 *    or, for a file copied from a disk, which is not read, the same path.
 * 3. The new original is stored at a path of its own: the original's path,
 *    with a version from its checksum, or from its location on its disk.
 * 4. In a transaction, under a lock on the media's row: the original's row
 *    is replaced, the renditions' rows removed, the runs that haven't ended
 *    canceled without touching the media, and the media gets its new
 *    metadata and goes back to `pending`. If this fails, the new file is
 *    deleted and nothing changed.
 * 5. Once committed: the previous files are deleted in a queued job, the
 *    canceled runs' transcoders are asked to stop them, `OriginalReplaced`
 *    is dispatched, and the probe queued; the pipeline follows it.
 */
class OriginalReplacer
{
    public const string CANCELED_REASON = 'The media\'s original was replaced.';

    public function __construct(
        private readonly MediaIngestor $ingestor,
        private readonly MediathequeConfig $config,
        private readonly PathFormatter $paths,
        private readonly Filesystems $filesystems,
        private readonly ConnectionResolverInterface $database,
        private readonly TranscoderManager $transcoders,
        private readonly ExceptionHandler $exceptions,
        private readonly Events $events,
        private readonly Jobs $jobs,
    ) {}

    /**
     * @throws FileRejected
     */
    public function replace(Media $media, Source $source, IngestOptions $options = new IngestOptions, bool $allowTypeChange = false): Media
    {
        if (! $media instanceof Model) {
            throw new InvalidArgumentException(sprintf('The original of %s can\'t be replaced: it is not an Eloquent media.', $media->uid()));
        }

        return $this->ingestor->accept($source, function (IncomingFile $file) use ($media, $options, $allowTypeChange) {
            if ($file->type->name !== $media->type() && ! $allowTypeChange) {
                throw FileRejected::because(RejectionReason::TypeChanged, sprintf(
                    'The file "%s" is a %s, and %s is a %s: its type can\'t change.',
                    $file->source->filename ?? 'file',
                    $file->type->name,
                    $media->uid(),
                    $media->type(),
                ));
            }

            return $this->store($media, $file, $options);
        });
    }

    private function store(Model&Media $media, IncomingFile $file, IngestOptions $options): Media
    {
        $pipeline = $options->pipeline !== null ? $this->config->pipeline($options->pipeline)->name : null;
        $current = $media->original();
        $disk = $options->source !== null
            ? $this->config->disk('source', $options->source)
            : ($current?->source() ?? $this->config->defaultSource());
        $checksum = $file->checksum();
        // The path is built with the media's new type, in case it changes
        $target = (clone $media)->forceFill(['type' => $file->type->name]);
        $path = $this->paths->original($target, $file->extension(), $this->version($file, $checksum));

        if (self::isCurrent($current, $disk, $path, $checksum)) {
            return $this->rename($media, $options);
        }

        $this->ingestor->write($file, $disk, $path, $media->visibility());

        try {
            $replaced = $this->database->connection($media->getConnectionName())->transaction(
                fn () => $this->swap($media, $file, $options, $disk, $path, $checksum),
            );
        } catch (Throwable $exception) {
            $this->filesystems->disk($disk)->delete($path);

            throw $exception;
        }

        if ($replaced === null) {
            // Another call stored the same file first
            $original = $media->original();
            if ($original === null || $original->path() !== $path || $original->source() !== $disk) {
                $this->filesystems->disk($disk)->delete($path);
            }

            return $this->rename($media, $options);
        }

        $this->afterCommit($media, $replaced, $checksum, $pipeline);

        return $media;
    }

    /**
     * The version in the new original's path: its checksum, or for a file
     * copied from a disk without reading it, its location, size and date. The
     * same file gets the same path, so storing it again creates no file.
     */
    private function version(IncomingFile $file, ?string $checksum): string
    {
        if ($checksum !== null) {
            return substr($checksum, 0, 16);
        }

        $disk = (string) $file->source->disk;
        $path = (string) $file->source->path;

        try {
            $modified = $this->filesystems->disk($disk)->lastModified($path);
        } catch (Throwable) {
            $modified = null;
        }

        return substr(hash('sha256', implode('|', [$disk, $path, $file->size, $modified])), 0, 16);
    }

    private static function isCurrent(?FileContract $original, string $disk, string $path, ?string $checksum): bool
    {
        return $original !== null && (
            ($checksum !== null && $original->checksum() === $checksum)
            || ($original->source() === $disk && $original->path() === $path)
        );
    }

    /**
     * The media is the same file already: only a name given changes.
     */
    private function rename(Model&Media $media, IngestOptions $options): Media
    {
        if ($options->name !== null && $options->name !== $media->name()) {
            $media->forceFill(['name' => $options->name])->save();
        }

        return $media;
    }

    /**
     * In the transaction: the media's row is locked and read again, so two
     * replacements of the same media run one after the other. Null when the
     * original is the new file already.
     *
     * @return array{previous: string|null, typeChanged: bool, files: array<string, list<string>>, runs: list<PipelineRun>, events: list<object>}|null
     */
    private function swap(Model&Media $media, IncomingFile $file, IngestOptions $options, string $disk, string $path, ?string $checksum): ?array
    {
        $locked = Models::media()::query()->withoutGlobalScopes()->whereKey($media->getKey())->lockForUpdate()->first()
            ?? throw new InvalidArgumentException(sprintf('The media %s is gone.', $media->uid()));
        $media->setRawAttributes($locked->getAttributes(), true);
        $files = Models::file()::query()->where('media_id', $media->getKey())->get();
        $media->setRelation('mediaFiles', $files);

        $previous = $media->original();
        if (self::isCurrent($previous, $disk, $path, $checksum)) {
            return null;
        }
        $typeChanged = $media->type() !== $file->type->name;

        $stale = [];
        foreach ($files as $existing) {
            $source = $existing->source();
            $stored = $existing->path();
            if ($source !== null && $stored !== null && ! ($source === $disk && $stored === $path)) {
                $stale[$source][] = $stored;
            }
            $existing->delete();
        }

        $original = Models::file()::query()->getModel()->newInstance();
        $original->forceFill([
            'media_id' => $media->getKey(),
            'handle' => FileContract::ORIGINAL,
            'role' => FileContract::ORIGINAL,
            'source' => $disk,
            'path' => $path,
            'name' => $file->source->filename,
            'mime' => $file->mime,
            'size' => $file->size,
            'checksum' => $checksum,
        ])->save();

        [$runs, $events] = $this->cancelRuns($media);

        $media->forceFill([
            'type' => $file->type->name,
            'name' => $options->name ?? $media->name(),
            'status' => MediaStatus::Pending,
            'metadata' => MediaIngestor::metadata($file, $checksum),
        ])->save();

        $media->setRelation('mediaFiles', $media->newCollection([$original]));
        $original->setRelation('media', $media);

        return ['previous' => $previous?->checksum(), 'typeChanged' => $typeChanged, 'files' => $stale, 'runs' => $runs, 'events' => $events];
    }

    /**
     * End the media's runs that haven't ended: their renditions are canceled
     * with a reason, and the runs too, without changing the media's status,
     * since it is replaced. A late report of their transcoder changes
     * nothing: statuses only move forward (docs/decisions/0012).
     *
     * @return array{list<PipelineRun>, list<object>}
     */
    private function cancelRuns(Model&Media $media): array
    {
        $runs = Models::pipelineRun()::query()
            ->where('pipelinable_type', $media->getMorphClass())
            ->where('pipelinable_id', $media->getKey())
            ->whereIn('status', [PipelineStatus::Pending, PipelineStatus::Running])
            ->lockForUpdate()
            ->with('pipelineJobs')
            ->get();

        $canceled = [];
        $events = [];
        $now = Date::now();

        foreach ($runs as $run) {
            if (! $run instanceof PipelineRun) {
                continue;
            }

            foreach ($run->pipelineJobs as $job) {
                if ($job->status()->canMoveTo(JobStatus::Canceled)) {
                    $job->forceFill(['status' => JobStatus::Canceled, 'reason' => self::CANCELED_REASON, 'ended_at' => $now])->save();
                    $events[] = new RenditionCanceled($job);
                }
            }

            $run->forceFill(['status' => PipelineStatus::Canceled, 'progress' => 1.0, 'ended_at' => $now])->save();
            $events[] = new PipelineCanceled($run);
            $canceled[] = $run;
        }

        return [$canceled, $events];
    }

    /**
     * Once committed: when the application replaces inside its own
     * transaction, after it commits.
     *
     * @param  array{previous: string|null, typeChanged: bool, files: array<string, list<string>>, runs: list<PipelineRun>, events: list<object>}  $replaced
     */
    private function afterCommit(Model&Media $media, array $replaced, ?string $checksum, ?string $pipeline): void
    {
        $then = function () use ($media, $replaced, $checksum, $pipeline) {
            foreach ($replaced['files'] as $source => $paths) {
                $this->jobs->dispatch(new DeleteStoredFiles($source, $paths));
            }

            foreach ($replaced['events'] as $event) {
                $this->events->dispatch($event);
            }

            foreach ($replaced['runs'] as $run) {
                $this->stop($run);
            }

            $this->events->dispatch(new OriginalReplaced($media, $replaced['previous'], $checksum));

            $key = $media->getKey();
            if (is_int($key) || is_string($key)) {
                $this->jobs->dispatch(new ProbeMedia($key, $pipeline ?? ($replaced['typeChanged'] ? null : $this->previousPipeline($media))));
            }
        };

        $connection = $this->database->connection($media->getConnectionName());
        $connection instanceof Connection ? $connection->afterCommit($then) : $then();
    }

    /**
     * Ask the transcoder of a canceled run to stop it, when it can. Its
     * renditions are canceled already, so a failure here only leaves the
     * driver's job running: it is reported, and the replacement stands.
     */
    private function stop(PipelineRun $run): void
    {
        if ($run->name() === PipelineDefinition::PROBE) {
            return;
        }

        try {
            $transcoder = $this->transcoders->driver($run->transcoder());
            if ($transcoder->capabilities()->cancel) {
                $transcoder->cancel($run);
            }
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);
        }
    }

    /**
     * The pipeline the media's last probe was to start, the one ingest
     * chose: kept for the new original while its type is the same.
     */
    private function previousPipeline(Model&Media $media): ?string
    {
        $last = Models::pipelineRun()::query()
            ->where('pipelinable_type', $media->getMorphClass())
            ->where('pipelinable_id', $media->getKey())
            ->where('name', PipelineDefinition::PROBE)
            ->latest('id')
            ->first();

        return $last instanceof PipelineRun ? $last->nextPipeline() : null;
    }
}
