<?php

namespace Folklore\Mediatheque\Events;

use Illuminate\Queue\SerializesModels;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesInterface;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;

class FileAttached
{
    use SerializesModels;

    public $model;
    public $file;

    /**
     * Create a new event instance.
     *
     * @return void
     */
    public function __construct(HasFilesInterface $model, FileContract $file)
    {
        $this->model = $model;
        $this->file = $file;
    }
}
