<?php
namespace Folklore\Mediatheque\Support\Traits;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Pipeline\Factory as PipelineFactory;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;

trait HasPipelines
{
    protected $typePipelineDisabled = false;

    /*
     *
     * Relationships
     *
     */
    public function pipelines()
    {
        $morphName = 'pipelinable';
        $model = app(PipelineContract::class);
        $modelClass = get_class($model);
        return $this->morphMany($modelClass, $morphName);
    }

    public function getPipelines(): Collection
    {
        return $this->pipelines;
    }

    public function getStartedPipelines(): Collection
    {
        return $this->pipelines()
            ->with('jobs')
            ->where('started', true)
            ->where('ended', false)
            ->where('failed', false)
            ->get();
    }

    public function hasPendingPipeline(string $name): bool
    {
        return $this->pipelines()
            ->where('name', $name)
            ->where('ended', false)
            ->where('failed', false)
            ->exists();
    }

    public function runPipeline($definition): ?PipelineContract
    {
        if (is_string($definition)) {
            $definition = resolve(PipelineFactory::class)->pipeline($definition);
        }

        $name = $definition->name();
        if ($definition->unique() && $this->hasPendingPipeline($name)) {
            return null;
        }

        $model = app(PipelineContract::class);
        $model->setDefinition($definition);
        $this->pipelines()->save($model);
        return $model;
    }

    public function typePipelineDisabled()
    {
        return $this->typePipelineDisabled;
    }

    public function withTypePipeline()
    {
        $this->typePipelineDisabled = false;
        return $this;
    }

    public function withoutTypePipeline()
    {
        $this->typePipelineDisabled = true;
        return $this;
    }
}
