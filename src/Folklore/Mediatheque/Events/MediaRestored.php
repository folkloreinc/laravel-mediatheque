<?php

namespace Folklore\Mediatheque\Events;

use Illuminate\Queue\SerializesModels;
use Folklore\Mediatheque\Models\Model;

class MediaRestored
{
    use SerializesModels;

    public $model;
    public $type;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct($type, Model $model)
    {
        $this->type = $type;
        $this->model = $model;
    }
}
