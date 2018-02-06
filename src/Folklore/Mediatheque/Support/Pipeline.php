<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesContract;

class Pipeline
{
    protected $defaultOptions = [
        'queue' => true,
        'from_file' => 'original',
        'namespace' => null,
    ];

    protected $options;

    protected $files;

    protected function files()
    {
        return [];
    }

    public function setOptions($options)
    {
        $this->options = array_merge($this->defaultOptions, $options);
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function setFiles($files)
    {
        $this->files = $files;
    }

    public function getFiles()
    {
        if (isset($this->files)) {
            return $this->files;
        }
        return $this->files();
    }

    public function run(HasFilesContract $model)
    {
        $fromFile = array_get($this->options, 'from_file');
        $file = $model->files->{$fromFile};
        if (isset($file)) {
            return;
        }
    }
}
