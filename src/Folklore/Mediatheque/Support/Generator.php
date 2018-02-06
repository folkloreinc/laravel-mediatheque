<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesContract;

abstract class Generator
{
    protected $defaultOptions = [];

    protected $options;

    abstract public function handle(FileContract $file, HasFilesContract $model);

    public function setOptions($options)
    {
        $this->options = array_merge($this->defaultOptions, $options);
    }

    public function getOptions()
    {
        return $this->options;
    }
}
