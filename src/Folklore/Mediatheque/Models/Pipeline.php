<?php

namespace Folklore\Mediatheque\Models;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineDefinitionContract;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Folklore\Mediatheque\Contracts\Models\Media as MediaContract;
use Folklore\Mediatheque\Support\Pipeline as PipelineDefinition;
use Folklore\Mediatheque\Jobs\RunPipeline;
use Folklore\Mediatheque\Observers\PipelineObserver;
use Exception;

class Pipeline extends Model implements PipelineContract
{
    protected $table = 'pipelines';

    protected $attributes = [
        'started' => false,
        'ended' => false,
        'failed' => false,
    ];

    protected $dates = ['started_at', 'ended_at', 'created_at', 'updated_at'];

    protected $casts = [
        'definition' => 'json',
        'started' => 'boolean',
        'ended' => 'boolean',
        'failed' => 'boolean',
    ];

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();
        static::observe(PipelineObserver::class);
    }

    public function jobs()
    {
        $model = app(PipelineJobContract::class);
        $modelClass = get_class($model);
        return $this->hasMany($modelClass);
    }

    public function setDefinition(PipelineDefinitionContract $definition): void
    {
        $this->name = $definition->name();
        $this->definition = $definition;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDefinition(): PipelineDefinitionContract
    {
        return new PipelineDefinition($this->name, $this->definition);
    }

    public function getJob(string $name): ?PipelineJobContract
    {
        return $this->jobs()
            ->where('name', $name)
            ->first();
    }

    public function addJob(PipelineJobContract $job): void
    {
        $this->jobs()->save($job);
    }

    public function getMedia(): MediaContract
    {
        $model = app($this->pipelinable_type)->find($this->pipelinable_id);
        return $model;
    }

    public function allJobsEnded(): bool
    {
        return $this->jobs()
            ->where('started', true)
            ->orWhere(function ($query) {
                $query->where('ended', false);
                $query->where('failed', false);
            })
            ->count() === 0;
    }

    public function hasFailedJobs(): bool
    {
        return $this->jobs()
            ->where('failed', true)
            ->count() > 0;
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->started_at = Carbon::now();
        $this->save();

        $shouldQueue = $this->getDefinition()->shouldQueue();
        $media = $this->getMedia();
        if ($shouldQueue) {
            RunPipeline::dispatch($this, $media);
        } else {
            RunPipeline::dispatchNow($this, $media);
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

    public function markFailed(Exception $e = null): void
    {
        $this->started = false;
        $this->failed = true;
        $this->ended_at = Carbon::now();
        if (!is_null($e)) {
            $this->failed_exception = $e;
        }
        $this->save();
    }
}
