<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Sources\LocalSource;

abstract class PipelineJob
{
    protected $defaultOptions = [];

    public $options;

    public $file;

    public $model;

    public function __construct(FileContract $file, $options = [], HasFilesContract $model = null)
    {
        $this->options = array_merge($this->defaultOptions, $options);
        $this->file = $file;
        $this->model = $model;
    }

    protected function getLocalFilePath($file)
    {
        // Get local path to file
        $source = $file->getSource();
        if ($source instanceof LocalSource) {
            $path = $source->getFullPath($file->path);
        } else {
            $ext = pathinfo($file->path, PATHINFO_EXTENSION);
            $path = tempnam(sys_get_temp_dir(), 'mediatheque_pipeline_job');
            if (!empty($ext)) {
                $path .= '.'.$ext;
            }
            $file->downloadFile($path);
        }

        return $path;
    }
}
