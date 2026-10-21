<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Contracts\Models\PipelineRun as PipelineRunContract;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * The production of one rendition in a run, stored in the `pipelines_jobs`
 * table.
 *
 * @property int $id
 * @property int $pipeline_id
 * @property string $name
 * @property JobStatus $status
 * @property float $progress
 * @property int $attempts
 * @property array{code?: mixed, message?: mixed}|null $error
 * @property string|null $reason
 * @property Carbon|null $queued_at
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read (Model&PipelineRunContract)|null $pipelineRun
 */
class PipelineJob extends Model implements PipelineJobContract
{
    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'queued',
        'progress' => 0,
        'attempts' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => JobStatus::class,
            'progress' => 'float',
            'attempts' => 'integer',
            'error' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return $this->table ?? Models::table('pipelines_jobs');
    }

    /**
     * @return BelongsTo<Model&PipelineRunContract, $this>
     */
    public function pipelineRun(): BelongsTo
    {
        return $this->belongsTo(Models::pipelineRun(), 'pipeline_id');
    }

    public function run(): PipelineRunContract
    {
        return $this->pipelineRun ?? throw new LogicException(sprintf('The pipeline job %d has no run.', $this->id));
    }

    public function rendition(): Rendition
    {
        return $this->run()->renditions()[$this->name]
            ?? throw new LogicException(sprintf('The run %s has no rendition "%s".', $this->run()->key(), $this->name));
    }

    public function status(): JobStatus
    {
        return $this->status;
    }

    public function progress(): float
    {
        return $this->progress;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function error(): ?TranscoderError
    {
        return $this->error !== null ? TranscoderError::fromArray($this->error) : null;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}
