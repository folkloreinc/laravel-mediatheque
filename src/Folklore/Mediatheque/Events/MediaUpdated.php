<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Models\Media;
use Illuminate\Queue\SerializesModels;

class MediaUpdated
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
