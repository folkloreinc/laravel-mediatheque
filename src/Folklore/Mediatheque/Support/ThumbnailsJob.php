<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Support\Interfaces\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Sources\LocalSource;

class ThumbnailsJob extends PipelineJob
{
    protected $type;

    protected $defaultThumbnailsOptions = [
        'count' => null,
        'extension' => '.jpg',
    ];

    public function __construct(FileContract $file, $options = [], HasFilesContract $model = null)
    {
        $this->options = array_merge($this->defaultThumbnailsOptions, $this->defaultOptions, $options);
        $this->file = $file;
        $this->model = $model;
    }

    public function handle()
    {
        $path = $this->getLocalFilePath($this->file);

        $service = app('mediatheque.services.thumbnail.'.$this->type);
        $count = array_get($this->options, 'count', null);
        $maxIndex = !is_null($count) ? $count : 1;
        $files = [];
        for ($i = 0; $i < $maxIndex; $i++) {
            $options = $this->getOptions($i);
            $destPath = $this->getDestinationPath($path, $i);
            $thumbnail = $service->createThumbnail($path, $destPath, $options);

            $newFile = app(FileContract::class);
            $newFile->setFile($destPath);
            $files[] = $newFile;
        }

        return is_null($count) ? $files[0] : $files;
    }

    protected function getDestinationPath($path, $index = 0)
    {
        // Replace extension
        $extension = array_get($this->options, 'extension', '');
        $filename = pathinfo($path, PATHINFO_FILENAME);
        return dirname($path).(!empty($extension) ? $filename.$extension : $filename);
    }

    protected function getOptions($index = 0)
    {
        return $this->options;
    }
}
