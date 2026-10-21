<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Contracts\Bus\Dispatcher as Jobs;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Retry, cancel and refresh runs, and probe media again, on the Eloquent
 * models (docs/decisions/0017). The dashboard and the commands act through
 * these, by way of the Mediatheque contract.
 *
 * Each action reads the run again, so it acts on its current state, and
 * does nothing to a run that has ended (but retry, which needs one).
 */
class RunActions
{
    public function __construct(
        private readonly PipelineRunner $runner,
        private readonly TranscoderManager $transcoders,
        private readonly MediathequeConfig $config,
        private readonly Recorder $recorder,
        private readonly Jobs $jobs,
    ) {}

    /**
     * A new run of the failed and canceled renditions of a run that ended,
     * forced, so a rendition that still has a file from an earlier run is
     * produced again. Renditions the pipeline doesn't have any more are left
     * out.
     */
    public function retry(PipelineRunContract $run): ?PipelineRunContract
    {
        $run = $this->fresh($run);

        if ($run === null || ! $run->status()->isFinal() || $run->name() === PipelineDefinition::PROBE) {
            return null;
        }

        $media = $run->media();
        if ($this->hasRunInProgress($media, $run->name())) {
            return null;
        }

        $pipeline = $this->config->pipeline($run->name());
        $renditions = [];
        foreach ($run->jobs() as $job) {
            $name = $job->rendition()->name;
            if (in_array($job->status(), [JobStatus::Failed, JobStatus::Canceled], true) && $pipeline->rendition($name) !== null) {
                $renditions[] = $name;
            }
        }

        return $renditions === [] ? null : $this->runner->run($media, $run->name(), $renditions, force: true);
    }

    /**
     * A probe that runs in the application's queue is canceled through the
     * recorder; one pending with its driver can't be.
     */
    public function cancel(PipelineRunContract $run): bool
    {
        $run = $this->fresh($run);

        if ($run === null || $run->status()->isFinal()) {
            return false;
        }

        if ($run->name() === PipelineDefinition::PROBE) {
            if ($run->externalId() !== null) {
                return false;
            }

            foreach ($run->jobs() as $job) {
                $this->recorder->canceled($job);
            }

            return true;
        }

        $transcoder = $this->transcoders->driver($run->transcoder());
        if (! $transcoder->capabilities()->cancel) {
            return false;
        }

        $transcoder->cancel($run);

        return true;
    }

    public function refresh(PipelineRunContract $run): bool
    {
        $run = $this->fresh($run);

        if ($run === null || $run->status()->isFinal() || $run->name() === PipelineDefinition::PROBE) {
            return false;
        }

        $transcoder = $this->transcoders->driver($run->transcoder());
        if (! $transcoder->capabilities()->refresh) {
            return false;
        }

        $transcoder->refresh($run);

        return true;
    }

    /**
     * A media whose probe failed is `failed` and has no `kind`: every probe
     * that completes writes one. It goes back to `pending`, and its probe is
     * queued with the pipeline its last probe was to start.
     */
    public function probeAgain(Media $media): bool
    {
        if (! $media instanceof Model || $media->status() !== MediaStatus::Failed || $media->metadata(MetadataKey::Kind->value) !== null) {
            return false;
        }

        $key = $media->getKey();
        if (! is_int($key) && ! is_string($key)) {
            return false;
        }

        $last = $this->runs($media, PipelineDefinition::PROBE)->latest('id')->first();

        $media->forceFill(['status' => MediaStatus::Pending])->save();
        $this->jobs->dispatch(new ProbeMedia($key, $last instanceof PipelineRun ? $last->nextPipeline() : null));

        return true;
    }

    /**
     * The run with its jobs, as they are now.
     */
    private function fresh(PipelineRunContract $run): ?PipelineRun
    {
        $fresh = Models::pipelineRun()::query()->where('uid', $run->key())->with('pipelineJobs')->first();

        return $fresh instanceof PipelineRun ? $fresh : null;
    }

    private function hasRunInProgress(Media $media, string $pipeline): bool
    {
        return $media instanceof Model
            && $this->runs($media, $pipeline)->whereIn('status', [PipelineStatus::Pending, PipelineStatus::Running])->exists();
    }

    /**
     * @return Builder<Model&PipelineRunContract>
     */
    private function runs(Model&Media $media, string $pipeline): Builder
    {
        return Models::pipelineRun()::query()
            ->where('pipelinable_type', $media->getMorphClass())
            ->where('pipelinable_id', $media->getKey())
            ->where('name', $pipeline);
    }
}
