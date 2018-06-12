<?php

namespace Folklore\Mediatheque\Events;

use Illuminate\Queue\SerializesModels;
use Folklore\Mediatheque\Models\Model;

class MediaUpdated
{
    use SerializesModels;

    public $model;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }
}
