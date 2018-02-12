<?php

namespace Folklore\Mediatheque\Observers;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Events\FileAttached;
use Folklore\Mediatheque\Events\FileDetached;

class FileObserver
{
    /**
     * Listen to the File deleting event.
     *
     * @param  FileContract  $model
     * @return void
     */
    public function deleting(FileContract $model)
    {
        $model->deleteFile();
    }

    /**
     * Listen to the File attached event.
     *
     * @param  FileAttached  $event
     * @return void
     */
    public function attached(FileAttached $event)
    {
        $model = $event->model;
        $file = $event->file;
        $modelFile = $model->files->first(function ($item, $key) use ($file) {
            if (!is_object($item)) {
                $item = $key;
            }
            return $item->id === $file->id;
        });
        $handle = $modelFile ? $modelFile->pivot->handle : null;
        if ($model instanceof HasPipelinesInterface && !is_null($handle)) {
            $pipelines = $model->pipelines()
                ->with('jobs')
                ->where('started', true)
                ->where('ended', false)
                ->where('failed', false)
                ->get();
            foreach ($pipelines as $pipeline) {
                foreach ($pipeline->jobs as $job) {
                    if ($job->isWaitingForFile($handle)) {
                        $job->run();
                    }
                }
            }
        }
    }

    public function detached(FileDetached $event)
    {
        // $event->model
    }
}
