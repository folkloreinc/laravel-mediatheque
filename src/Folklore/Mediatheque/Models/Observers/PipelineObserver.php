<?php

namespace Folklore\Mediatheque\Models\Observers;

use Illuminate\Bus\Dispatcher;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;
use Folklore\Mediatheque\Jobs\RunPipeline;

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
        if ($model->definition->autostart) {
            $model->start();
        }
    }
}
