<?php

namespace Folklore\Mediatheque\Models\Observers;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;

class FileObserver
{
    /**
     * Listen to the User deleting event.
     *
     * @param  User  $user
     * @return void
     */
    public function deleting(FileContract $model)
    {
        $model->deleteFile();
    }
}
