<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\Uid;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use LogicException;

/**
 * A pipeline run, stored in the `pipelines` table (docs/decisions/0012).
 *
 * `definition` is a JSON snapshot of the renditions the run produces, closed
 * under `from`, and of the name of its pipeline's main rendition, as they
 * were when the run was created.
 *
 * @property int $id
 * @property string $uid
 * @property string $name
 * @property string $pipelinable_type
 * @property int $pipelinable_id
 * @property string $transcoder
 * @property string|null $external_id
 * @property array{transcoder?: string, source?: string|null, renditions?: array<string, array<string, mixed>>, main?: string|null, then?: string|null} $definition
 * @property string $output_source
 * @property string $path_prefix
 * @property Visibility $visibility
 * @property PipelineStatus $status
 * @property float $progress
 * @property int $attempts
 * @property array{code?: mixed, message?: mixed}|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read (Model&MediaContract)|null $pipelinable
 * @property-read EloquentCollection<int, Model&PipelineJobContract> $pipelineJobs
 */
class PipelineRun extends Model implements PipelineRunContract
{
    public const string UID_PREFIX = 'run';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'progress' => 0,
        'attempts' => 0,
    ];

    /**
     * A uid is set on insert when none was set, even when model events are
     * faked.
     *
     * @var bool
     */
    public $usesUniqueIds = true;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uid'];
    }

    public function newUniqueId(): string
    {
        return Uid::generate(self::UID_PREFIX);
    }

    /**
     * Every column read by a contract method of the same name has a cast, so
     * Eloquent reads it as an attribute, never as a relation.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uid' => 'string',
            'name' => 'string',
            'transcoder' => 'string',
            'definition' => 'array',
            'visibility' => Visibility::class,
            'status' => PipelineStatus::class,
            'progress' => 'float',
            'attempts' => 'integer',
            'error' => 'array',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? Models::table('pipelines');
    }

    /**
     * The media the run is on, deleted or not, whatever the application's
     * global scopes: workers must find it.
     *
     * @return MorphTo<Model, $this>
     */
    public function pipelinable(): MorphTo
    {
        return $this->morphTo()->withoutGlobalScopes();
    }

    /**
     * @return HasMany<Model&PipelineJobContract, $this>
     */
    public function pipelineJobs(): HasMany
    {
        return $this->hasMany(Models::pipelineJob(), 'pipeline_id')->chaperone('pipelineRun');
    }

    /**
     * The definition snapshot of a run of these renditions, closed under
     * `from`.
     *
     * @param  array<string, Rendition>  $renditions
     * @return array{transcoder: string, source: string|null, renditions: array<string, array<string, mixed>>, main: string|null}
     */
    public static function snapshot(PipelineDefinition $pipeline, array $renditions): array
    {
        return [
            'transcoder' => $pipeline->transcoder,
            'source' => $pipeline->source,
            'renditions' => array_map(fn (Rendition $rendition) => $rendition->toArray(), $renditions),
            'main' => $pipeline->mainRendition()?->name,
        ];
    }

    public function key(): string
    {
        return $this->uid;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function media(): MediaContract
    {
        $media = $this->pipelinable;

        return $media instanceof MediaContract
            ? $media
            : throw new LogicException(sprintf('The pipeline run %s is not on a media.', $this->uid));
    }

    public function input(): FileContract
    {
        return $this->media()->original() ?? throw new LogicException(sprintf('The media %s has no original to run a pipeline on.', $this->media()->uid()));
    }

    public function transcoder(): string
    {
        return $this->transcoder;
    }

    public function externalId(): ?string
    {
        return $this->external_id;
    }

    public function status(): PipelineStatus
    {
        return $this->status;
    }

    public function renditions(): array
    {
        return PipelineDefinition::fromArray($this->name, [
            'transcoder' => $this->transcoder,
            'renditions' => $this->definition['renditions'] ?? [],
        ])->renditions;
    }

    /**
     * The name of the main rendition of the run's pipeline, when the run was
     * created: the media's status follows it (docs/decisions/0014).
     */
    public function mainRendition(): ?string
    {
        return $this->definition['main'] ?? null;
    }

    /**
     * For a pending probe: the pipeline to run once it completes, when
     * ingest chose one.
     */
    public function nextPipeline(): ?string
    {
        return $this->definition['then'] ?? null;
    }

    public function jobs(): Collection
    {
        return self::collect(array_values($this->pipelineJobs->all()));
    }

    /**
     * @param  list<PipelineJobContract>  $jobs
     * @return Collection<int, PipelineJobContract>
     */
    private static function collect(array $jobs): Collection
    {
        return new Collection($jobs);
    }

    public function job(string $rendition): ?PipelineJobContract
    {
        return $this->pipelineJobs->first(fn (Model $job) => $job->getAttribute('name') === $rendition);
    }

    public function outputSource(): string
    {
        return $this->output_source;
    }

    public function pathPrefix(): string
    {
        return $this->path_prefix;
    }

    public function visibility(): Visibility
    {
        return $this->visibility;
    }

    public function progress(): float
    {
        $jobs = $this->pipelineJobs;

        if ($jobs->isEmpty()) {
            return $this->status->isFinal() ? 1.0 : 0.0;
        }

        return $jobs->sum(fn (PipelineJobContract $job) => $job->status()->isFinal() ? 1.0 : $job->progress()) / $jobs->count();
    }

    public function error(): ?TranscoderError
    {
        return $this->error !== null ? TranscoderError::fromArray($this->error) : null;
    }

    /**
     * Whether every job of the run has a final status.
     */
    public function allJobsEnded(): bool
    {
        return $this->pipelineJobs->every(fn (PipelineJobContract $job) => $job->status()->isFinal());
    }

    /**
     * Whether a job of the run has this status.
     */
    public function hasJobWithStatus(JobStatus $status): bool
    {
        return $this->pipelineJobs->contains(fn (PipelineJobContract $job) => $job->status() === $status);
    }
}
