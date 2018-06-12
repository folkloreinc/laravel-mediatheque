<?php

namespace Folklore\Mediatheque\Observers;

use Folklore\Mediatheque\Models\Model;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;

class MediaObserver
{
    public function created(Model $model)
    {
        $this->handleEvent('created', $model);

        if ($model instanceof HasPipelinesInterface && $type = $this->getTypeFromModel($model)) {
            $pipeline = $type->getPipeline();
            if (!is_null($pipeline)) {
                $model->runPipeline($pipeline);
            }
        }
    }

    public function updated(Model $model)
    {
        $this->handleEvent('updated', $model);
    }

    public function saved(Model $model)
    {
        $this->handleEvent('saved', $model);
    }

    public function deleting(Model $model)
    {
        $this->handleEvent('deleting', $model);
    }

    public function restored(Model $model)
    {
        $this->handleEvent('restored', $model);
    }

    protected function handleEvent($name, $model)
    {
        $className = config('mediatheque.events.'.$name, null);
        if (!is_null($className)) {
            $event = new $className($model);
            event($event);
        }
    }

    protected function getTypeFromModel($model)
    {
        if (isset($model->type)) {
            return mediatheque()->type($model->type);
        }
        return null;
    }
}
