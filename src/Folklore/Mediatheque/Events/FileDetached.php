<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesInterface;
use Illuminate\Queue\SerializesModels;

class FileDetached
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
