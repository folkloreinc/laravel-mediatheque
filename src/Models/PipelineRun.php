<?php

namespace Folklore\Mediatheque\Models;

use Carbon\CarbonInterface;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\Progress;
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
 * were when the run was created. The renditions have the run's options
 * merged in; `options` keeps those options, for retries (docs/decisions/0027).
 *
 * @property int $id
 * @property string $uid
 * @property string $name
 * @property string $pipelinable_type
 * @property int $pipelinable_id
 * @property string $transcoder
 * @property string|null $external_id
 * @property array{transcoder?: string, source?: string|null, renditions?: array<string, array<string, mixed>>, main?: string|null, options?: array<string, array<string, mixed>>, then?: string|null, import?: array<string, mixed>, refresh?: bool} $definition
 * @property string $output_source
 * @property string $path_prefix
 * @property Visibility $visibility
 * @property PipelineStatus $status
 * @property float|null $progress The run's progress as the recorder last stored it, for queries; progress() computes it
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
     * `from`, with the options of the run that apply to them.
     *
     * @param  array<string, Rendition>  $renditions  With the run's options merged in
     * @param  array<string, array<string, mixed>>  $options  The run's options, by rendition
     * @return array{transcoder: string, source: string|null, renditions: array<string, array<string, mixed>>, main: string|null, options?: array<string, array<string, mixed>>}
     */
    public static function snapshot(PipelineDefinition $pipeline, array $renditions, array $options = []): array
    {
        $snapshot = [
            'transcoder' => $pipeline->transcoder,
            'source' => $pipeline->source,
            'renditions' => array_map(fn (Rendition $rendition) => $rendition->toArray(), $renditions),
            'main' => $pipeline->mainRendition()?->name,
        ];

        $options = array_filter(array_intersect_key($options, $renditions), fn (array $values) => $values !== []);
        if ($options !== []) {
            $snapshot['options'] = $options;
        }

        return $snapshot;
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
     * The options the run was created with, by rendition: retry() creates
     * the new run with them (docs/decisions/0027).
     *
     * @return array<string, array<string, mixed>>
     */
    public function runOptions(): array
    {
        return $this->definition['options'] ?? [];
    }

    /**
     * Whether this probe run refreshes an embed that was probed before
     * (docs/decisions/0031): its outcome goes to EmbedRefreshes, and never
     * moves the media back to `pending` or `failed`.
     */
    public function isRefresh(): bool
    {
        return ($this->definition['refresh'] ?? false) === true;
    }

    /**
     * The import of an embed's file that this run makes, if any: its mode
     * (an `ImportMode` value) and its rendition of `embed.download`
     * (docs/decisions/0030). retry() creates the new run with it.
     *
     * @return array{mode: string, rendition: string}|null
     */
    public function embedImport(): ?array
    {
        $import = $this->definition['import'] ?? null;

        return is_array($import) && is_string($import['mode'] ?? null) && is_string($import['rendition'] ?? null)
            ? ['mode' => $import['mode'], 'rendition' => $import['rendition']]
            : null;
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

    public function progress(): ?float
    {
        return Progress::ofRun($this);
    }

    public function error(): ?TranscoderError
    {
        return $this->error !== null ? TranscoderError::fromArray($this->error) : null;
    }

    public function createdAt(): ?CarbonInterface
    {
        return $this->created_at;
    }

    public function startedAt(): ?CarbonInterface
    {
        return $this->started_at;
    }

    public function endedAt(): ?CarbonInterface
    {
        return $this->ended_at;
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
