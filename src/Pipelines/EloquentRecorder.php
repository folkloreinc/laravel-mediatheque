<?php

namespace Folklore\Mediatheque\Pipelines;

use Closure;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
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
use Folklore\Mediatheque\Events\RenditionCanceled;
use Folklore\Mediatheque\Events\RenditionCompleted;
use Folklore\Mediatheque\Events\RenditionFailed;
use Folklore\Mediatheque\Events\RenditionSkipped;
use Folklore\Mediatheque\Jobs\DeleteStoredFiles;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Probe\ProbeResults;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Bus\Dispatcher as Jobs;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * The package's recorder, on the Eloquent models (docs/decisions/0011,
 * 0012, 0014).
 *
 * Each call locks its run's row, reads the run and its jobs again, and
 * changes them in a transaction, so concurrent workers and webhooks
 * reconcile in any order. Statuses only move forward: a call that would
 * move a rendition back, or change a final status, changes nothing. Events
 * are dispatched after the commit.
 */
class EloquentRecorder implements Recorder
{
    /** @var list<object> */
    private array $events = [];

    /** @var list<array{string, list<string>}> */
    private array $replaced = [];

    private int $depth = 0;

    public function __construct(
        private readonly ConnectionResolverInterface $database,
        private readonly Events $dispatcher,
        private readonly Jobs $jobs,
        private readonly Filesystems $filesystems,
        private readonly LocalFiles $files,
        private readonly ProbeResults $probes,
    ) {}

    public function started(PipelineJob $job): void
    {
        $this->record($job, function (Model&PipelineJob $job, PipelineRun $run) {
            $this->start($job, $run);
        });
    }

    public function progress(PipelineJob $job, float $progress): void
    {
        $this->record($job, function (Model&PipelineJob $job, PipelineRun $run) use ($progress) {
            if ($job->status()->isFinal()) {
                return;
            }

            $this->start($job, $run);
            $job->forceFill(['progress' => max($job->progress(), min(1.0, max(0.0, $progress)))])->save();
        });
    }

    public function completed(PipelineJob $job, Outputs $outputs): void
    {
        $outputs = $this->complete($job, $outputs);

        $this->record($job, function (Model&PipelineJob $job, PipelineRun $run) use ($outputs) {
            if (! $job->status()->canMoveTo(JobStatus::Completed)) {
                return;
            }

            $this->runStarted($run);
            $job->forceFill(['status' => JobStatus::Completed, 'progress' => 1.0, 'ended_at' => Date::now()])->save();

            if ($run->name() === PipelineDefinition::PROBE) {
                $this->afterCommit(fn () => $this->probes->done($this->media($run), $outputs->metadata, $run->nextPipeline()));
            } else {
                $this->attach($job, $run, $outputs);
            }

            $this->events[] = new RenditionCompleted($job, $outputs);
            $this->afterFinalStatus($run);
        });
    }

    public function skipped(PipelineJob $job, string $reason): void
    {
        $this->record($job, function (Model&PipelineJob $job, PipelineRun $run) use ($reason) {
            $this->skip($job, $run, $reason);
            $this->afterFinalStatus($run);
        });
    }

    public function failed(PipelineJob $job, TranscoderError $error): void
    {
        $this->record($job, function (Model&PipelineJob $job, PipelineRun $run) use ($error) {
            $this->fail($job, $run, $error);
            $this->afterFinalStatus($run);
        });
    }

    public function canceled(PipelineJob $job): void
    {
        $this->record($job, function (Model&PipelineJob $job, PipelineRun $run) {
            $this->cancel($job, $run);
            $this->afterFinalStatus($run);
        });
    }

    /**
     * Run a change on the job and its run, read again under a lock on the
     * run's row, then dispatch its events once committed.
     *
     * @param  Closure(Model&PipelineJob, PipelineRun): void  $change
     */
    private function record(PipelineJob $job, Closure $change): void
    {
        if (! $job instanceof Model) {
            throw new InvalidArgumentException(sprintf('The recorder records Eloquent pipeline jobs, not %s.', $job::class));
        }

        $this->depth++;

        try {
            $this->database->connection($job->getConnectionName())->transaction(function () use ($job, $change) {
                $run = Models::pipelineRun()::query()
                    ->whereKey($job->getAttribute('pipeline_id'))
                    ->lockForUpdate()
                    ->with('pipelineJobs')
                    ->first();

                if (! $run instanceof PipelineRun) {
                    throw new InvalidArgumentException('The run of the pipeline job is gone.');
                }

                $fresh = $run->pipelineJobs->first(fn (Model $candidate) => $candidate->getKey() === $job->getKey());
                if (! $fresh instanceof PipelineJob) {
                    throw new InvalidArgumentException('The pipeline job is gone.');
                }

                $change($fresh, $run);
            });
        } finally {
            $this->depth--;
        }

        if ($this->depth === 0) {
            $this->flush();
        }
    }

    private function start(Model&PipelineJob $job, PipelineRun $run): void
    {
        if (! $job->status()->canMoveTo(JobStatus::Running)) {
            return;
        }

        $job->forceFill(['status' => JobStatus::Running, 'started_at' => Date::now()])->save();
        $this->runStarted($run);
    }

    private function skip(Model&PipelineJob $job, PipelineRun $run, string $reason): void
    {
        if (! $job->status()->canMoveTo(JobStatus::Skipped)) {
            return;
        }

        $this->runStarted($run);
        $job->forceFill(['status' => JobStatus::Skipped, 'reason' => $reason, 'ended_at' => Date::now()])->save();
        $this->events[] = new RenditionSkipped($job, $reason);

        foreach ($this->dependents($job, $run) as $dependent) {
            $this->skip($dependent, $run, sprintf('The rendition "%s" it reads from was skipped.', $job->rendition()->name));
        }
    }

    private function fail(Model&PipelineJob $job, PipelineRun $run, TranscoderError $error): void
    {
        if (! $job->status()->canMoveTo(JobStatus::Failed)) {
            return;
        }

        $this->runStarted($run);
        $job->forceFill(['status' => JobStatus::Failed, 'error' => $error->toArray(), 'ended_at' => Date::now()])->save();
        $this->events[] = new RenditionFailed($job, $error);

        if ($run->name() === PipelineDefinition::PROBE) {
            $this->afterCommit(fn () => $this->probes->failed($this->media($run), $error));
        }

        foreach ($this->dependents($job, $run) as $dependent) {
            $this->fail($dependent, $run, TranscoderError::dependencyFailed($job->rendition()->name));
        }
    }

    private function cancel(Model&PipelineJob $job, PipelineRun $run): void
    {
        if (! $job->status()->canMoveTo(JobStatus::Canceled)) {
            return;
        }

        $job->forceFill(['status' => JobStatus::Canceled, 'ended_at' => Date::now()])->save();
        $this->events[] = new RenditionCanceled($job);

        if ($run->name() === PipelineDefinition::PROBE) {
            $this->afterCommit(fn () => $this->probes->failed($this->media($run), new TranscoderError(TranscoderErrorCode::OperationFailed, 'The probe was canceled.')));
        }

        foreach ($this->dependents($job, $run) as $dependent) {
            $this->cancel($dependent, $run);
        }
    }

    /**
     * The jobs of the same run that read from this one.
     *
     * @return list<Model&PipelineJob>
     */
    private function dependents(PipelineJob $job, PipelineRun $run): array
    {
        $name = $job->rendition()->name;

        return array_values($run->pipelineJobs
            ->filter(fn (PipelineJob $other) => $other->rendition()->from === $name)
            ->all());
    }

    private function runStarted(PipelineRun $run): void
    {
        if ($run->status() !== PipelineStatus::Pending) {
            return;
        }

        $run->forceFill(['status' => PipelineStatus::Running, 'started_at' => Date::now()])->save();
        $this->events[] = new PipelineStarted($run);
    }

    /**
     * Fill what the driver didn't report and the recorder needs: the size
     * and checksum of each file, read from its disk (docs/decisions/0013).
     * Done before the lock, since it reads files; nothing is read for a job
     * that already ended, or for a file the disk doesn't have.
     */
    private function complete(PipelineJob $job, Outputs $outputs): Outputs
    {
        if ($job->status()->isFinal() || ! $job->rendition()->keep || ! $outputs->hasFiles()) {
            return $outputs;
        }

        $run = $job->run();
        $files = [];

        foreach ($outputs->files as $file) {
            $disk = $this->filesystems->disk($file->source ?? $run->outputSource());
            if (($file->size !== null && $file->checksum !== null) || ! $disk->exists($file->path)) {
                $files[] = $file;

                continue;
            }

            $files[] = new OutputFile(
                path: $file->path,
                source: $file->source,
                index: $file->index,
                role: $file->role,
                mime: $file->mime,
                size: $file->size ?? $disk->size($file->path),
                checksum: $file->checksum ?? $this->files->checksum($file->source ?? $run->outputSource(), $file->path),
                width: $file->width,
                height: $file->height,
                duration: $file->duration,
                bitrate: $file->bitrate,
                partsCount: $file->partsCount,
                metadata: $file->metadata,
            );
        }

        return new Outputs($files, $outputs->metadata);
    }

    /**
     * Attach the files of a rendition to its media, replacing the files of
     * the same handle, and write its metadata outputs (docs/decisions/0011).
     * Replaced files are deleted from their disk in a queued job.
     */
    private function attach(Model&PipelineJob $job, PipelineRun $run, Outputs $outputs): void
    {
        $rendition = $job->rendition();
        $media = $this->media($run);

        if (! $rendition->keep || ! $outputs->hasFiles()) {
            if ($outputs->metadata !== []) {
                $media->forceFill(['metadata' => [...$media->metadata(), ...$outputs->metadata]])->save();
            }

            return;
        }

        $kept = [];
        foreach ($outputs->files as $output) {
            $kept[] = ($output->source ?? $run->outputSource()).'|'.$output->path;
        }

        $previous = Models::file()::query()
            ->where('media_id', $media->getKey())
            ->where('handle', $rendition->name)
            ->get();

        $stale = [];
        foreach ($previous as $file) {
            $source = $file->source();
            $path = $file->path();
            if ($source !== null && $path !== null && ! in_array($source.'|'.$path, $kept, true)) {
                $stale[$source][] = $path;
            }
            $file->delete();
        }

        foreach ($stale as $source => $paths) {
            $this->replaced[] = [$source, $paths];
        }

        foreach ($outputs->files as $position => $output) {
            $file = Models::file()::query()->getModel()->newInstance();
            $file->forceFill([
                'media_id' => $media->getKey(),
                'handle' => $rendition->name,
                'index' => $output->index,
                'role' => $output->role ?? $rendition->role,
                'source' => $output->source ?? $run->outputSource(),
                'path' => $output->path,
                'name' => basename($output->path),
                'mime' => $output->mime,
                'size' => $output->size,
                'checksum' => $output->checksum,
                'width' => $output->width,
                'height' => $output->height,
                'duration' => $output->duration,
                'bitrate' => $output->bitrate,
                'parts_count' => $output->partsCount,
                'metadata' => $position === 0 ? [...$output->metadata, ...$outputs->metadata] : $output->metadata,
                'definition_hash' => $rendition->definitionHash(),
                'pipeline_job_id' => $job->getKey(),
            ])->save();
        }

        $media->unsetRelation('mediaFiles');
    }

    /**
     * When every job of the run has a final status, the run ends: `failed`
     * if one failed, else `canceled` if one was canceled, else `completed`.
     * Then the media's status follows its main rendition.
     */
    private function afterFinalStatus(PipelineRun $run): void
    {
        if ($run->status()->isFinal() || ! $run->allJobsEnded()) {
            return;
        }

        $this->runStarted($run);

        $status = match (true) {
            $run->hasJobWithStatus(JobStatus::Failed) => PipelineStatus::Failed,
            $run->hasJobWithStatus(JobStatus::Canceled) => PipelineStatus::Canceled,
            default => PipelineStatus::Completed,
        };
        $error = $run->pipelineJobs->first(fn (PipelineJob $job) => $job->status() === JobStatus::Failed)?->error();

        $run->forceFill([
            'status' => $status,
            'progress' => 1.0,
            'error' => $error?->toArray(),
            'ended_at' => Date::now(),
        ])->save();

        $this->events[] = match ($status) {
            PipelineStatus::Failed => new PipelineFailed($run),
            PipelineStatus::Canceled => new PipelineCanceled($run),
            default => new PipelineCompleted($run),
        };

        $this->updateMedia($run);
    }

    /**
     * After a run that includes the media's main rendition: `ready` if it
     * completed, `failed` otherwise, unless the media is ready and still has
     * a file for it (docs/decisions/0014).
     */
    private function updateMedia(PipelineRun $run): void
    {
        $main = $run->mainRendition();
        $job = $main !== null ? $run->job($main) : null;

        if ($main === null || $job === null) {
            return;
        }

        $media = $this->media($run);
        $media->unsetRelation('mediaFiles');
        $status = $job->status() === JobStatus::Completed ? MediaStatus::Ready : MediaStatus::Failed;
        $keepsPlaying = $status === MediaStatus::Failed && $media->status() === MediaStatus::Ready && $media->file($main) !== null;

        if ($media->status() === $status || $keepsPlaying) {
            return;
        }

        $media->forceFill(['status' => $status])->save();
        $this->events[] = $status === MediaStatus::Ready ? new MediaReady($media) : new MediaFailed($media, $job->error());
    }

    private function media(PipelineRun $run): Model&MediaContract
    {
        $media = $run->media();

        return $media instanceof Model ? $media : throw new InvalidArgumentException('The recorder records runs of Eloquent media.');
    }

    /**
     * @param  Closure(): void  $callback
     */
    private function afterCommit(Closure $callback): void
    {
        $this->events[] = $callback;
    }

    private function flush(): void
    {
        [$events, $replaced] = [$this->events, $this->replaced];
        $this->events = [];
        $this->replaced = [];

        foreach ($replaced as [$source, $paths]) {
            $this->jobs->dispatch(new DeleteStoredFiles($source, $paths));
        }

        foreach ($events as $event) {
            $event instanceof Closure ? $event() : $this->dispatcher->dispatch($event);
        }
    }
}
