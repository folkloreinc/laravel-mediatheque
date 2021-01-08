<?php

namespace Folklore\Mediatheque\Events;

use Illuminate\Queue\SerializesModels;
use Folklore\Mediatheque\Models\Media;

class MediaCreated
{
    use SerializesModels;

    public $model;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Media $model)
    {
        $this->model = $model;
    }
}
