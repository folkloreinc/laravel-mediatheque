<?php

namespace Folklore\Mediatheque\Models;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Jobs\RunPipelineJob;

class PipelineJob extends Model implements PipelineJobContract
{
    protected $table = 'pipelines_jobs';

    protected $attributes = [
        'started' => false,
        'ended' => false,
        'failed' => false,
    ];

    protected $dates = [
        'started_at',
        'ended_at',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'definition' => 'json',
        'started' => 'boolean',
        'ended' => 'boolean',
        'failed' => 'boolean',
        'failed_exception' => 'string'
    ];

    public function pipeline()
    {
        $pipelineClass = get_class(app(PipelineContract::class));
        return $this->belongsTo($pipelineClass);
    }

    public function setDefinition(array $definition): void
    {
        $this->name = data_get($definition, 'name');
        $this->definition = Arr::except($definition, ['name']);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDefinition(): array
    {
        return $this->definition;
    }

    public function run(): void
    {
        if ($this->started) {
            return;
        }

        $definition = $this->getDefinition();
        $pipeline = $this->pipeline;
        $pipelineDefinition = $pipeline->getDefinition();
        $media = $pipeline->getMedia();
        $shouldQueue = data_get($definition, 'should_queue', $pipelineDefinition->shouldQueue());
        $fromFile = data_get($definition, 'from_file', $pipelineDefinition->fromFile());

        $file = $media->getFile($fromFile);
        if (!$file) {
            return;
        }

        if ($shouldQueue) {
            RunPipelineJob::dispatch($this, $media);
        } else {
            RunPipelineJob::dispatchNow($this, $media);
        }
    }

    public function markStarted(): void
    {
        $this->started = true;
        $this->started_at = Carbon::now();
        $this->save();
    }

    public function markEnded(): void
    {
        $this->started = false;
        $this->ended = true;
        $this->ended_at = Carbon::now();
        $this->save();
    }

    public function markFailed($e = null): void
    {
        $this->started = false;
        $this->failed = true;
        $this->ended_at = Carbon::now();
        if (!is_null($e)) {
            $this->failed_exception = $e;
        }
        $this->save();
    }

    public function canRun($model = null): bool
    {
        if (is_null($model)) {
            $model = $this->pipeline->getMedia();
        }
        $fromFile = $this->definition['from_file'];
        return (
            !$this->started &&
            !$this->ended &&
            !$this->failed &&
            $model->hasFile($fromFile)
        );
    }

    public function isWaitingForFile($name): bool
    {
        return (
            !$this->started &&
            !$this->ended &&
            !$this->failed &&
            data_get($this->definition, 'from_file') === $name
        );
    }
}
