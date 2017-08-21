<?php

namespace Folklore\Mediatheque\Models\Observers;

use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesContract;

class HasFilesObserver
{
    /**
     * Listen to the User deleting event.
     *
     * @param  User  $user
     * @return void
     */
    public function deleting(HasFilesContract $model)
    {
        $model->load('files');
        foreach ($model->files as $file) {
            $file->delete();
        }
    }
}
