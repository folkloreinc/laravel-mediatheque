<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;
use Folklore\Mediatheque\Contracts\Models\PipelineJob as PipelineJobContract;

class Pipeline extends Model implements PipelineContract
{
    protected $table = 'pipelines';

    public function jobs()
    {
        $model = app(PipelineJobContract::class);
        $modelClass = get_class($model);
        return $this->hasMany($modelClass);
    }
}
