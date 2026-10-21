<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Exceptions\InvalidPipeline;
use Folklore\Mediatheque\Ingest\PathFormatter;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Transcoders\TranscoderManager;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * Creates pipeline runs and submits them to their transcoder
 * (docs/decisions/0005, 0012).
 *
 * - A run produces the renditions that are missing, failed, or whose
 *   definition changed (`definition_hash`), unless it is forced.
 * - Its renditions are closed under `from`.
 * - It records its output source (the pipeline's, or the original's), its
 *   path prefix (`run_path_format`) and the media's visibility.
 * - A run is never retried in place: running a pipeline again is a new run.
 */
class PipelineRunner
{
    public function __construct(
        private readonly MediathequeConfig $config,
        private readonly PathFormatter $paths,
        private readonly TranscoderManager $transcoders,
        private readonly ConnectionResolverInterface $database,
    ) {}

    /**
     * Create a run and submit it. Null when there is nothing to produce.
     *
     * @param  list<string>|null  $renditions  Only these renditions (and those they read from)
     */
    public function run(Media $media, ?string $pipeline = null, ?array $renditions = null, bool $force = false): ?PipelineRun
    {
        $definition = $this->definition($media, $pipeline);
        $names = $renditions ?? array_keys(array_filter($definition->renditions, fn (Rendition $rendition) => $rendition->keep));

        if (! $force) {
            $names = array_values(array_filter($names, fn (string $name) => ! $this->isUpToDate($media, $definition, $name)));
        }

        if ($names === []) {
            return null;
        }

        $run = $this->create($media, $definition, $names);
        $externalId = $this->transcoders->driver($run->transcoder())->submit($run);

        if ($externalId !== null) {
            $run->forceFill(['external_id' => $externalId])->save();
        }

        return $run;
    }

    /**
     * A run with its jobs queued, not submitted. A job that reads from
     * another rendition gets its `queued_at` when its input completes.
     *
     * @param  list<string>  $renditions
     */
    public function create(Media $media, PipelineDefinition $pipeline, array $renditions): PipelineRun
    {
        if (! $media instanceof Model) {
            throw new InvalidArgumentException('Pipelines run on Eloquent media.');
        }

        $original = $media->original() ?? throw new InvalidArgumentException(sprintf('The media %s has no original to run a pipeline on.', $media->uid()));
        $selected = $pipeline->closeUnderFrom($renditions);
        $transcoder = $this->transcoders->driver($pipeline->transcoder);

        foreach ($selected as $rendition) {
            if (! $transcoder->supports($rendition->operation)) {
                throw new InvalidPipeline(sprintf(
                    'The transcoder "%s" of the pipeline "%s" doesn\'t support the operation "%s" of the rendition "%s".',
                    $pipeline->transcoder,
                    $pipeline->name,
                    $rendition->operation,
                    $rendition->name,
                ));
            }
        }

        $uid = Uid::generate(PipelineRun::UID_PREFIX);
        $run = Models::pipelineRun()::query()->getModel()->newInstance();

        if (! $run instanceof PipelineRun) {
            throw new InvalidArgumentException('The pipeline run model must extend '.PipelineRun::class.'.');
        }

        $this->database->connection($run->getConnectionName())->transaction(function () use ($run, $uid, $media, $pipeline, $selected, $original) {
            $run->forceFill([
                'uid' => $uid,
                'name' => $pipeline->name,
                'pipelinable_type' => $media->getMorphClass(),
                'pipelinable_id' => $media->getKey(),
                'transcoder' => $pipeline->transcoder,
                'definition' => PipelineRun::snapshot($pipeline, $selected),
                'output_source' => $pipeline->source ?? $original->source() ?? $this->config->defaultSource(),
                'path_prefix' => $this->paths->runPrefix($media, $uid),
                'visibility' => $media->visibility(),
            ])->save();

            $now = Date::now();
            foreach ($selected as $name => $rendition) {
                $job = Models::pipelineJob()::query()->getModel()->newInstance();
                $job->forceFill([
                    'pipeline_id' => $run->getKey(),
                    'name' => $name,
                    'queued_at' => $rendition->from === null ? $now : null,
                ])->save();
            }
        });

        $run->setRelation('pipelinable', $media);
        $run->load('pipelineJobs');

        return $run;
    }

    private function definition(Media $media, ?string $pipeline): PipelineDefinition
    {
        if ($pipeline !== null) {
            return $this->config->pipeline($pipeline);
        }

        return $this->config->pipelineOfType($media->type())
            ?? throw new InvalidArgumentException(sprintf('The type "%s" has no pipeline.', $media->type()));
    }

    /**
     * Whether the media has the files of a rendition, produced by its
     * current definition.
     */
    private function isUpToDate(Media $media, PipelineDefinition $pipeline, string $name): bool
    {
        $rendition = $pipeline->rendition($name) ?? throw new InvalidPipeline(sprintf('The pipeline "%s" has no rendition "%s".', $pipeline->name, $name));
        $file = $media->file($name);

        return $file instanceof Model && $file->getAttribute('definition_hash') === $rendition->definitionHash();
    }
}
