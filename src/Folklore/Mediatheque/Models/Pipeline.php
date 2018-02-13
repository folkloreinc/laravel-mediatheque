<?php

namespace Folklore\Mediatheque\Models;

use Carbon\Carbon;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Illuminate\Bus\Dispatcher;
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

    protected $dates = [
        'started_at',
        'ended_at',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'started' => 'boolean',
        'ended' => 'boolean',
        'failed' => 'boolean'
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

    public function getModel()
    {
        $model = app($this->pipelinable_type)->find($this->pipelinable_id);
        return $model;
    }

    public function allJobsEnded()
    {
        return $this->jobs()
            ->where('started', true)
            ->orWhere(function ($query) {
                $query->where('ended', false);
                $query->where('failed', false);
            })
            ->count() === 0;
    }

    public function hasFailedJobs()
    {
        return $this->jobs()
            ->where('failed', true)
            ->count() > 0;
    }

    public function start()
    {
        if ($this->started) {
            return;
        }

        $this->started = true;
        $this->started_at = Carbon::now();
        $this->save();

        $shouldQueue = $this->definition->queue;
        $model = $this->getModel();
        $job = new RunPipeline($model, $this);
        if ($shouldQueue) {
            app(Dispatcher::class)->dispatch($job);
        } else {
            app(Dispatcher::class)->dispatchNow($job);
        }
    }

    public function markStarted()
    {
        $this->started = true;
        $this->started_at = Carbon::now();
        $this->save();
    }

    public function markEnded()
    {
        $this->started = false;
        $this->ended = true;
        $this->ended_at = Carbon::now();
        $this->save();
    }

    public function markFailed(Exception $e = null)
    {
        $this->started = false;
        $this->failed = true;
        $this->ended_at = Carbon::now();
        if (!is_null($e)) {
            $this->failed_exception = $e;
        }
        $this->save();
    }

    public function setDefinitionAttribute($value)
    {
        if (!isset($this->attributes['name'])) {
            $this->attributes['name'] = $value->getName();
        }
        $this->attributes['definition'] = is_object($value) ? serialize($value) : $value;
    }

    public function getDefinitionAttribute()
    {
        $definition = array_get($this->attributes, 'definition', null);
        return is_string($definition) ? unserialize($definition) : $definition;
    }
}
