<?php namespace Folklore\Mediatheque\Support\Traits;

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
        $morphName = 'morphable';
        $model = app(PipelineContract::class);
        $modelClass = get_class($model);
        $query = $this->morphMany($modelClass, $morphName)
                        ->withTimestamps()
                        ->orderBy('order', 'asc');
        return $query;
    }
}
