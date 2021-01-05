<?php
namespace Folklore\Mediatheque\Support\Traits;

use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;

trait HasPipelines
{
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

    public function getRunningPipeline($name)
    {
        return $this->pipelines()
            ->where('name', $name)
            ->where('ended', false)
            ->first();
    }

    public function runPipeline($pipeline)
    {
        if (is_string($pipeline)) {
            $pipeline = app('mediatheque')->pipeline($pipeline);
        }

        $name = $pipeline->getName();
        if ($pipeline->unique && $this->getRunningPipeline($name)) {
            return;
        }

        $pipelineModel = app(PipelineContract::class);
        $pipelineModel->definition = $pipeline;
        $this->pipelines()->save($pipelineModel);
        return $pipelineModel;
    }
}
