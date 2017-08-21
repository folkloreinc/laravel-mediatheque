<?php

namespace Folklore\Mediatheque\Events;

use Illuminate\Queue\SerializesModels;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Broadcasting\InteractsWithSockets;
use Folklore\Mediatheque\Models\Model;

class MediaUpdated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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
