<?php

namespace Folklore\Mediatheque\Models;

use Carbon\Carbon;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;
use Illuminate\Bus\Dispatcher;
use Folklore\Mediatheque\Jobs\RunPipelineJob;
use Exception;

class PipelineJob extends Model implements PipelineJobContract
{
    protected $table = 'pipelines_jobs';

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = [
        'started_at',
        'ended_at',
        'created_at',
        'updated_at'
    ];

    protected $casts = [
        'definition' => 'array',
        'started' => 'boolean',
        'ended' => 'boolean',
        'failed' => 'boolean'
    ];

    public function pipeline()
    {
        $pipelineClass = get_class(app(PipelineContract::class));
        return $this->belongsTo($pipelineClass);
    }

    public function run()
    {
        if ($this->started) {
            return;
        }

        $definition = $this->definition;
        $pipeline = $this->pipeline;
        $pipelineDefinition = $pipeline->definition;
        $model = app($pipeline->pipelinable_type)->find($pipeline->pipelinable_id);
        $shouldQueue = array_get($definition, 'queue', $pipelineDefinition->queue);
        $fromFile = array_get($definition, 'from_file', $pipelineDefinition->from_file);

        $file = $model->files->{$fromFile};
        if (!$file) {
            return;
        }

        $job = new RunPipelineJob($model, $pipeline, $this);
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

    public function isWaitingForFile($name)
    {
        return (
            !$this->started &&
            !$this->ended &&
            !$this->failed &&
            array_get($this->definition, 'from_file') === $name
        );
    }

    public function setFailedExceptionAttribute($e)
    {
        $this->attributes['failed_exception'] = (string) $e;
    }
}
