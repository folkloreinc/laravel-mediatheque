<?php

namespace Folklore\Mediatheque\Testing;

use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
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
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Testing\Models\FakeFile;
use Folklore\Mediatheque\Testing\Models\FakePipelineJob;
use Folklore\Mediatheque\Testing\Models\FakePipelineRun;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;

/**
 * The fake's recorder, on in-memory models. It follows the rules of the
 * Recorder contract, and passes the same contract tests as the package's
 * recorder.
 */
class InMemoryRecorder implements Recorder
{
    public function __construct(
        private readonly Dispatcher $events,
        private readonly UrlGenerator $urls,
    ) {}

    public function started(PipelineJob $job): void
    {
        $job = $this->fake($job);
        if (! $job->status->canMoveTo(JobStatus::Running)) {
            return;
        }

        $job->status = JobStatus::Running;
        $this->runStarted($job->run);
    }

    public function progress(PipelineJob $job, float $progress): void
    {
        $job = $this->fake($job);
        if ($job->status->isFinal()) {
            return;
        }

        $this->started($job);
        $job->progress = max($job->progress, min(1.0, max(0.0, $progress)));
    }

    public function completed(PipelineJob $job, Outputs $outputs): void
    {
        $job = $this->fake($job);
        if (! $job->status->canMoveTo(JobStatus::Completed)) {
            return;
        }

        $this->runStarted($job->run);
        $job->status = JobStatus::Completed;
        $job->progress = 1.0;
        $this->record($job, $outputs);

        $this->events->dispatch(new RenditionCompleted($job, $outputs));
        $this->afterFinalStatus($job->run);
    }

    public function skipped(PipelineJob $job, string $reason): void
    {
        $job = $this->fake($job);
        if (! $job->status->canMoveTo(JobStatus::Skipped)) {
            return;
        }

        $this->runStarted($job->run);
        $job->status = JobStatus::Skipped;
        $job->reason = $reason;
        $this->events->dispatch(new RenditionSkipped($job, $reason));

        foreach ($this->dependents($job) as $dependent) {
            $this->skipped($dependent, sprintf('The rendition "%s" it reads from was skipped.', $job->rendition->name));
        }

        $this->afterFinalStatus($job->run);
    }

    public function failed(PipelineJob $job, TranscoderError $error): void
    {
        $job = $this->fake($job);
        if (! $job->status->canMoveTo(JobStatus::Failed)) {
            return;
        }

        $this->runStarted($job->run);
        $job->status = JobStatus::Failed;
        $job->error = $error;
        $this->events->dispatch(new RenditionFailed($job, $error));

        foreach ($this->dependents($job) as $dependent) {
            $this->failed($dependent, TranscoderError::dependencyFailed($job->rendition->name));
        }

        $this->afterFinalStatus($job->run);
    }

    public function canceled(PipelineJob $job): void
    {
        $job = $this->fake($job);
        if (! $job->status->canMoveTo(JobStatus::Canceled)) {
            return;
        }

        $job->status = JobStatus::Canceled;
        $this->events->dispatch(new RenditionCanceled($job));

        foreach ($this->dependents($job) as $dependent) {
            $this->canceled($dependent);
        }

        $this->afterFinalStatus($job->run);
    }

    private function fake(PipelineJob $job): FakePipelineJob
    {
        if (! $job instanceof FakePipelineJob) {
            throw new InvalidArgumentException(sprintf('The in-memory recorder only records the fake\'s jobs, not %s.', $job::class));
        }

        return $job;
    }

    /**
     * The jobs of the same run that read from this one.
     *
     * @return list<FakePipelineJob>
     */
    private function dependents(FakePipelineJob $job): array
    {
        return array_values(array_filter(
            $job->run->jobs,
            fn (FakePipelineJob $other) => $other->rendition->from === $job->rendition->name,
        ));
    }

    private function runStarted(FakePipelineRun $run): void
    {
        if ($run->status !== PipelineStatus::Pending) {
            return;
        }

        $run->status = PipelineStatus::Running;
        $this->events->dispatch(new PipelineStarted($run));
    }

    /**
     * Attach the files and write the metadata outputs (docs/decisions/0011).
     */
    private function record(FakePipelineJob $job, Outputs $outputs): void
    {
        $rendition = $job->rendition;
        $media = $job->run->media;

        if (! $rendition->keep || ! $outputs->hasFiles()) {
            $media->metadata = [...$media->metadata, ...$outputs->metadata];

            return;
        }

        $media->files = array_values(array_filter($media->files, fn (FakeFile $file) => $file->handle !== $rendition->name));

        foreach ($outputs->files as $position => $output) {
            $media->attach(new FakeFile(
                media: $media,
                urls: $this->urls,
                handle: $rendition->name,
                source: $output->source ?? $job->run->outputSource,
                path: $output->path,
                index: $output->index,
                role: $output->role ?? $rendition->role,
                mime: $output->mime,
                size: $output->size,
                checksum: $output->checksum,
                width: $output->width,
                height: $output->height,
                duration: $output->duration,
                bitrate: $output->bitrate,
                partsCount: $output->partsCount,
                metadata: $position === 0 ? [...$output->metadata, ...$outputs->metadata] : $output->metadata,
                definitionHash: $rendition->definitionHash(),
            ));
        }
    }

    private function afterFinalStatus(FakePipelineRun $run): void
    {
        if ($run->status->isFinal()) {
            return;
        }

        $statuses = array_map(fn (FakePipelineJob $job) => $job->status, array_values($run->jobs));
        foreach ($statuses as $status) {
            if (! $status->isFinal()) {
                return;
            }
        }

        $this->runStarted($run);

        if (in_array(JobStatus::Failed, $statuses, true)) {
            $run->status = PipelineStatus::Failed;
            $this->events->dispatch(new PipelineFailed($run));
        } elseif (in_array(JobStatus::Canceled, $statuses, true)) {
            $run->status = PipelineStatus::Canceled;
            $this->events->dispatch(new PipelineCanceled($run));
        } else {
            $run->status = PipelineStatus::Completed;
            $this->events->dispatch(new PipelineCompleted($run));
        }

        $this->updateMedia($run);
    }

    /**
     * After a run that includes the media's main rendition: ready if it
     * completed, failed otherwise, unless the media is ready and still has a
     * file for it (docs/decisions/0014).
     */
    private function updateMedia(FakePipelineRun $run): void
    {
        $media = $run->media;
        $main = $media->mainRendition;

        if ($main === null || ! isset($run->jobs[$main])) {
            return;
        }

        $job = $run->jobs[$main];
        $status = $job->status === JobStatus::Completed ? MediaStatus::Ready : MediaStatus::Failed;

        // A ready media keeps playing its previous file (docs/decisions/0014)
        $keepsPlaying = $status === MediaStatus::Failed && $media->status === MediaStatus::Ready && $media->file($main) !== null;
        if ($media->status === $status || $keepsPlaying) {
            return;
        }

        $media->status = $status;
        $this->events->dispatch($status === MediaStatus::Ready
            ? new MediaReady($media)
            : new MediaFailed($media, $job->error));
    }
}
