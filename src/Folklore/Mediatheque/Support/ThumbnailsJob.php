<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Services\Thumbnail as ThumbnailService;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;

class ThumbnailsJob extends PipelineJob
{
    protected $type;

    protected $defaultThumbnailsOptions = [
        'count' => null,
        'path_format' => '{dirname}/{filename}-{name}-{index}.{extension}',
        'extension' => 'jpg'
    ];

    public function __construct(
        FileContract $file,
        $options = [],
        HasFilesContract $model = null
    ) {
        $this->options = array_merge(
            $this->defaultThumbnailsOptions,
            $this->defaultOptions,
            $options
        );
        $this->file = $file;
        $this->model = $model;
    }

    public function handle(ThumbnailService $thumbnailService)
    {
        $path = $this->getLocalFilePath($this->file);

        $count = data_get($this->options, 'count', null);
        $maxIndex = !is_null($count) ? $count : 1;
        $files = [];
        for ($i = 0; $i < $maxIndex; $i++) {
            $options = $this->getOptions($i);
            $destinationPath = $this->formatDestinationPath($path, [
                'index' => $i
            ]);
            $thumbnail = $thumbnailService->getThumbnail(
                $path,
                $destinationPath,
                $options
            );
            $files[] = $this->makeFileFromPath($destinationPath);
        }

        return is_null($count) ? $files[0] : $files;
    }

    protected function getOptions($index = 0)
    {
        return $this->options;
    }
}
