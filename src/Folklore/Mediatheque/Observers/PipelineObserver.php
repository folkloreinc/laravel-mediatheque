<?php

namespace Folklore\Mediatheque\Observers;

use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;

class PipelineObserver
{
    /**
     * Listen to the User deleting event.
     *
     * @param  User  $user
     * @return void
     */
    public function created(PipelineContract $model)
    {
        if ($model->getDefinition()->autoStart()) {
            $model->start();
        }
    }
}
