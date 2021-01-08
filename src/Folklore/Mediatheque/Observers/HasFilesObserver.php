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
        $model->getFiles()->forEach(function ($file) {
            $file->delete();
        });
    }
}
