<?php

namespace Folklore\Mediatheque\Observers;

use Folklore\Mediatheque\Models\Model;
use Folklore\Mediatheque\Contracts\Support\HasPipelines as HasPipelinesInterface;

class MediaObserver
{
    public function created(Model $model)
    {
        $type = $model->getType();
        if (!is_null($type)) {
            $pipeline = $type->pipeline();
            if (!is_null($pipeline)) {
                $model->runPipeline($pipeline);
            }
        }
    }

    public function deleting(Model $model)
    {
        $model->load('files');
        $model->getFiles()->forEach(function ($file) {
            $file->delete();
        });
    }
}
