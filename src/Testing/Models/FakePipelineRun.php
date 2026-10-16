<?php

namespace Folklore\Mediatheque\Testing\Models;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Support\Collection;
use LogicException;

/**
 * An in-memory pipeline run of the fake.
 */
class FakePipelineRun implements PipelineRun
{
    /** @var array<string, FakePipelineJob> */
    public array $jobs = [];

    public PipelineStatus $status = PipelineStatus::Pending;

    public ?string $externalId = null;

    public ?TranscoderError $error = null;

    /**
     * @param  array<string, Rendition>  $renditions  Closed under `from`
     */
    public function __construct(
        public string $key,
        public FakeMedia $media,
        public PipelineDefinition $pipeline,
        public array $renditions,
        public string $outputSource,
        public string $pathPrefix,
    ) {
        foreach ($renditions as $name => $rendition) {
            $this->jobs[$name] = new FakePipelineJob($this, $rendition);
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->pipeline->name;
    }

    public function media(): Media
    {
        return $this->media;
    }

    public function input(): File
    {
        return $this->media->original() ?? throw new LogicException(sprintf('The media %s has no original to run a pipeline on.', $this->media->uid));
    }

    public function transcoder(): string
    {
        return $this->pipeline->transcoder;
    }

    public function externalId(): ?string
    {
        return $this->externalId;
    }

    public function status(): PipelineStatus
    {
        return $this->status;
    }

    public function renditions(): array
    {
        return $this->renditions;
    }

    public function jobs(): Collection
    {
        return self::collect(array_values($this->jobs));
    }

    public function job(string $rendition): ?PipelineJob
    {
        return $this->jobs[$rendition] ?? null;
    }

    public function outputSource(): string
    {
        return $this->outputSource;
    }

    public function pathPrefix(): string
    {
        return $this->pathPrefix;
    }

    public function visibility(): Visibility
    {
        return $this->media->visibility;
    }

    public function progress(): float
    {
        if ($this->jobs === []) {
            return $this->status->isFinal() ? 1.0 : 0.0;
        }

        return array_sum(array_map(
            fn (FakePipelineJob $job) => $job->status->isFinal() ? 1.0 : $job->progress,
            $this->jobs,
        )) / count($this->jobs);
    }

    public function error(): ?TranscoderError
    {
        return $this->error;
    }

    /**
     * @param  list<PipelineJob>  $jobs
     * @return Collection<int, PipelineJob>
     */
    private static function collect(array $jobs): Collection
    {
        return new Collection($jobs);
    }
}
