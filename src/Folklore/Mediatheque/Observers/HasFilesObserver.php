<?php

namespace Folklore\Mediatheque\Observers;

use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;

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
