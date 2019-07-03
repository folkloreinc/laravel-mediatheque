<?php

namespace Folklore\Mediatheque\Support;

use Illuminate\Support\Str;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Services\PathFormatter as PathFormatterService;
use Folklore\Mediatheque\Sources\LocalSource;

abstract class PipelineJob
{
    protected $defaultOptions = [
        'path_format' => '{dirname}/{filename}-{name}.{extension}',
    ];

    public $options;

    public $file;

    public $model;

    protected $localFilePath = null;

    public function __construct(
        FileContract $file,
        $options = [],
        HasFilesContract $model = null
    ) {
        $this->options = array_merge($this->defaultOptions, $options);
        $this->file = $file;
        $this->model = $model;
    }

    protected function getLocalFilePath($file)
    {
        if (isset($this->localFilePath)) {
            return $this->localFilePath;
        }

        // Get local path to file
        $source = $file->getSource();
        if ($source instanceof LocalSource) {
            $path = $source->getFullPath($file->path);
        } else {
            $ext = app('files')->extension($file->path);
            $path = tempnam(sys_get_temp_dir(), 'mediatheque_pipeline_job');
            if (!empty($ext)) {
                $path .= '.' . $ext;
            }
            $file->downloadFile($path);
        }
        $this->localFilePath = $path;

        return $this->localFilePath;
    }

    protected function formatDestinationPath($path, ...$replaces)
    {
        $pathParts = pathinfo($path);
        $format = array_get(
            $this->options,
            'path_format',
            '{dirname}/{filename}-{name}.{extension}'
        );
        $destinationPath = app(PathFormatterService::class)->formatPath(
            $format,
            [
                'name' => Str::slug(class_basename(get_class($this)))
            ],
            $pathParts,
            $this->options,
            ...$replaces
        );
        return $destinationPath;
    }

    protected function makeFileFromPath($path)
    {
        $file = app(FileContract::class);
        $file->setFile($path);
        return $file;
    }
}
