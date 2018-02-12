<?php

namespace Folklore\Mediatheque\Models;

use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Support\Interfaces\HasUrl as HasUrlInterface;
use Folklore\Mediatheque\Support\Interfaces\HasPipelines as HasPipelinesInterface;
use Folklore\Mediatheque\Support\Traits\HasFiles;
use Folklore\Mediatheque\Support\Traits\HasUrl;
use Folklore\Mediatheque\Support\Traits\HasPipelines;

class Media extends Model implements
    HasFilesInterface,
    HasUrlInterface,
    HasPipelinesInterface
{
    use HasFiles, HasUrl, HasPipelines;

    /**
     * The "booting" method of the model.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        $observer = config('mediatheque.observers.media', null);
        if (!is_null($observer)) {
            static::observe($observer);
        }
    }
}
