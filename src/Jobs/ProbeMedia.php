<?php

namespace Folklore\Mediatheque\Jobs;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Ingest\PathFormatter;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Probe\ProbeResults;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Probes a media's original, in the queue (docs/decisions/0012).
 *
 * The media is loaded without the application's global scopes, so a tenant
 * scope that depends on the request doesn't hide it. A media that is not
 * `pending` any more is left alone, so a retried job changes nothing.
 */
class ProbeMedia implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $tries;

    public int $timeout;

    public function __construct(
        public readonly int|string $mediaId,
    ) {
        $config = app(MediathequeConfig::class);
        $this->tries = $config->jobsTries();
        $this->timeout = $config->jobsTimeout();
        $this->onConnection($config->jobsConnection());
        $this->onQueue($config->jobsQueue());
    }

    public function handle(Prober $prober, ProbeResults $results, LocalFiles $files, PathFormatter $paths): void
    {
        $media = $this->media();
        $original = $media?->original();

        if ($media === null || $original === null || $media->status() !== MediaStatus::Pending) {
            return;
        }

        $source = $original->source();
        $path = $original->path();
        if ($original instanceof Model && $original->checksum() === null && $source !== null && $path !== null) {
            $checksum = $files->checksum($source, $path);
            $original->forceFill(['checksum' => $checksum])->save();
            $media->forceFill(['metadata' => [...$media->metadata(), MetadataKey::Checksum->value => $checksum]])->save();
        }

        $outcome = $prober->probe($original);

        if ($outcome->isFailed() && $outcome->error !== null) {
            $results->failed($media, $outcome->error);
        } elseif ($outcome->isPending() && $outcome->externalId !== null) {
            $this->recordPendingProbe($media, $outcome->externalId, $paths);
        } else {
            $results->done($media, $outcome->metadata);
        }
    }

    /**
     * After the last try: the media fails, so it doesn't stay pending.
     */
    public function failed(Throwable $exception): void
    {
        $media = $this->media();

        if ($media !== null) {
            app(ProbeResults::class)->failed($media, new TranscoderError(TranscoderErrorCode::OperationFailed, 'The probe failed: '.$exception->getMessage()));
        }
    }

    private function media(): (Model&Media)|null
    {
        return Models::media()::query()->withoutGlobalScopes()->find($this->mediaId);
    }

    /**
     * A probe that takes longer is a run of the `probe` pipeline, which its
     * driver completes through the recorder.
     */
    private function recordPendingProbe(Model&Media $media, string $externalId, PathFormatter $paths): void
    {
        $pipeline = new PipelineDefinition(PipelineDefinition::PROBE, [new Rendition('probe', 'probe')], transcoder: 'prober');
        $uid = Uid::generate(PipelineRun::UID_PREFIX);

        $run = Models::pipelineRun()::query()->getModel()->newInstance();
        $run->forceFill([
            'uid' => $uid,
            'name' => PipelineDefinition::PROBE,
            'pipelinable_type' => $media->getMorphClass(),
            'pipelinable_id' => $media->getKey(),
            'transcoder' => $pipeline->transcoder,
            'external_id' => $externalId,
            'definition' => PipelineRun::snapshot($pipeline, $pipeline->renditions),
            'output_source' => $media->original()?->source() ?? '',
            'path_prefix' => $paths->runPrefix($media, $uid),
            'visibility' => $media->visibility(),
        ])->save();

        $job = Models::pipelineJob()::query()->getModel()->newInstance();
        $job->forceFill(['pipeline_id' => $run->getKey(), 'name' => 'probe'])->save();
    }
}
