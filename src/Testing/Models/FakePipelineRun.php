<?php

namespace Folklore\Mediatheque\Testing\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\Progress;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
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

    public CarbonImmutable $createdAt;

    public ?CarbonImmutable $startedAt = null;

    public ?CarbonImmutable $endedAt = null;

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
        $this->createdAt = CarbonImmutable::instance(Date::now());

        foreach ($renditions as $name => $rendition) {
            $this->jobs[$name] = new FakePipelineJob($this, $rendition);
            $this->jobs[$name]->queuedAt = $rendition->from === null ? $this->createdAt : null;
        }

        $media->runs[] = $this;
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

    public function progress(): ?float
    {
        return Progress::ofRun($this);
    }

    public function error(): ?TranscoderError
    {
        return $this->error;
    }

    public function createdAt(): ?CarbonInterface
    {
        return $this->createdAt;
    }

    public function startedAt(): ?CarbonInterface
    {
        return $this->startedAt;
    }

    public function endedAt(): ?CarbonInterface
    {
        return $this->endedAt;
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
