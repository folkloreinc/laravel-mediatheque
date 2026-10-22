<?php

namespace Folklore\Mediatheque\Jobs;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Contracts\Recorder;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Ingest\PathFormatter;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Throwable;

/**
 * Probes a media's original, in the queue (docs/decisions/0012, 0017).
 *
 * Every probe is a run of the `probe` pipeline, completed through the
 * recorder: its duration, attempts and error are recorded as any run's. A
 * prober that answers at once completes the run here; one that answers
 * later (pending) records its external id, and its driver completes the run.
 *
 * The media is loaded without the application's global scopes, so a tenant
 * scope that depends on the request doesn't hide it. A media that is not
 * `pending` any more is left alone, and a retried job reuses its run, so a
 * retry changes nothing that already happened. An original replaced while
 * it is read records nothing.
 */
class ProbeMedia implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** The one rendition of a probe run. */
    private const string RENDITION = 'probe';

    public int $tries;

    public int $timeout;

    /**
     * @param  string|null  $pipeline  The pipeline to run once probed; the type's by default
     */
    public function __construct(
        public readonly int|string $mediaId,
        public readonly ?string $pipeline = null,
    ) {
        $config = app(MediathequeConfig::class);
        $this->tries = $config->jobsTries();
        $this->timeout = $config->jobsTimeout();
        $this->onConnection($config->jobsConnection());
        $this->onQueue($config->jobsQueue());
    }

    public function handle(Prober $prober, Recorder $recorder, LocalFiles $files, PathFormatter $paths): void
    {
        $media = $this->media();
        $original = $media?->original();

        if ($media === null || $original === null || $media->status() !== MediaStatus::Pending) {
            return;
        }

        $run = $this->run($media, $paths);
        $job = $run->job(self::RENDITION);

        if (! $job instanceof Model || $run->externalId() !== null || $job->status()->isFinal()) {
            return;
        }

        $job->forceFill(['attempts' => $job->attempts() + 1])->save();

        $source = $original->source();
        $path = $original->path();
        if ($original instanceof Model && $original->checksum() === null && $source !== null && $path !== null) {
            $checksum = $files->checksum($source, $path);

            // An original replaced while it was read is gone: its run is canceled, and the new
            // original has its own probe (docs/decisions/0019)
            if (Models::file()::query()->whereKey($original->getKey())->whereNull('checksum')->update(['checksum' => $checksum]) === 0) {
                return;
            }

            $original->forceFill(['checksum' => $checksum])->syncOriginalAttribute('checksum');
            $media->forceFill(['metadata' => [...$media->metadata(), MetadataKey::Checksum->value => $checksum]])->save();
        }

        $recorder->started($job);
        $outcome = $prober->probe($original);

        if ($outcome->isFailed() && $outcome->error !== null) {
            $recorder->failed($job, $outcome->error);
        } elseif ($outcome->isPending() && $outcome->externalId !== null) {
            $run->forceFill(['external_id' => $outcome->externalId])->save();
        } else {
            $recorder->completed($job, new Outputs(metadata: $outcome->metadata));
        }
    }

    /**
     * After the last try: the probe fails, and so does the media, so it
     * doesn't stay pending.
     */
    public function failed(Throwable $exception): void
    {
        $media = $this->media();

        if ($media === null || $media->status() !== MediaStatus::Pending) {
            return;
        }

        $job = $this->run($media, app(PathFormatter::class))->job(self::RENDITION);
        $error = new TranscoderError(TranscoderErrorCode::OperationFailed, 'The probe failed: '.$exception->getMessage());

        if ($job !== null) {
            app(Recorder::class)->failed($job, $error);
        }
    }

    private function media(): (Model&Media)|null
    {
        return Models::media()::query()->withoutGlobalScopes()->find($this->mediaId);
    }

    /**
     * The media's probe run that hasn't ended, from a previous try, or a new
     * one, with its job queued.
     */
    private function run(Model&Media $media, PathFormatter $paths): PipelineRun
    {
        $open = Models::pipelineRun()::query()
            ->where('pipelinable_type', $media->getMorphClass())
            ->where('pipelinable_id', $media->getKey())
            ->where('name', PipelineDefinition::PROBE)
            ->whereIn('status', [PipelineStatus::Pending, PipelineStatus::Running])
            ->with('pipelineJobs')
            ->latest('id')
            ->first();

        if ($open instanceof PipelineRun) {
            return $open;
        }

        $pipeline = new PipelineDefinition(PipelineDefinition::PROBE, [new Rendition(self::RENDITION, 'probe')], transcoder: 'prober');
        $uid = Uid::generate(PipelineRun::UID_PREFIX);

        $run = Models::pipelineRun()::query()->getModel()->newInstance();
        if (! $run instanceof PipelineRun) {
            throw new InvalidArgumentException('The pipeline run model must extend '.PipelineRun::class.'.');
        }

        $run->forceFill([
            'uid' => $uid,
            'name' => PipelineDefinition::PROBE,
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->getKey(),
            'transcoder' => $pipeline->transcoder,
            'definition' => [...PipelineRun::snapshot($pipeline, $pipeline->renditions), 'then' => $this->pipeline],
            'output_source' => $media->original()?->source() ?? '',
            'path_prefix' => $paths->runPrefix($media, $uid),
            'visibility' => $media->visibility(),
        ])->save();

        $job = Models::pipelineJob()::query()->getModel()->newInstance();
        $job->forceFill(['pipeline_id' => $run->getKey(), 'name' => self::RENDITION, 'queued_at' => Date::now()])->save();

        $run->setRelation('pipelinable', $media);
        $run->load('pipelineJobs');

        return $run;
    }
}
