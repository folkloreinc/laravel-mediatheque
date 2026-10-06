<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Support\HasFiles as HasFilesContract;
use Folklore\Mediatheque\Services\PathFormatter as PathFormatterService;
use Folklore\Mediatheque\Sources\LocalSource;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

abstract class PipelineJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $defaultOptions = [
        'path_format' => '{dirname}/{filename}-{name}.{extension}',
    ];

    public $options;

    public $file;

    public $model;

    protected $localFilePath = null;

    protected $temporaryFiles = [];

    protected $protectedFiles = [];

    public function __construct(FileContract $file, $options = [], ?HasFilesContract $model = null)
    {
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
            // The stored file itself: never delete it
            $this->protectedFiles[] = $path;
        } else {
            $ext = app('files')->extension($file->path);
            $path = tempnam(sys_get_temp_dir(), 'mediatheque_pipeline_job');
            $this->addTemporaryFile($path);
            if (! empty($ext)) {
                $path .= '.'.$ext;
                $this->addTemporaryFile($path);
            }
            $file->downloadFile($path);
        }
        $this->localFilePath = $path;

        return $this->localFilePath;
    }

    protected function formatDestinationPath($path, ...$replaces)
    {
        $pathParts = pathinfo($path);
        $format = data_get(
            $this->options,
            'path_format',
            '{dirname}/{filename}-{name}.{extension}'
        );
        $destinationPath = app(PathFormatterService::class)->formatPath(
            $format,
            [
                'name' => Str::slug(class_basename(get_class($this))),
            ],
            $pathParts,
            $this->options,
            ...$replaces
        );

        return $destinationPath;
    }

    protected function makeFileFromPath($path): FileContract
    {
        $file = app(FileContract::class);
        $file->setFile($path);

        // The file is now copied on its source
        $this->addTemporaryFile($path);

        return $file;
    }

    protected function addTemporaryFile(string $path): void
    {
        $this->temporaryFiles[] = $path;
    }

    /**
     * Delete the local files created while running the job
     */
    public function deleteTemporaryFiles(): void
    {
        $files = app('files');
        $protectedFiles = array_filter(array_map('realpath', $this->protectedFiles));
        foreach (array_unique($this->temporaryFiles) as $path) {
            if ($files->isFile($path) && ! in_array(realpath($path), $protectedFiles, true)) {
                $files->delete($path);
            }
        }
        $this->temporaryFiles = [];
        $this->localFilePath = null;
    }
}
