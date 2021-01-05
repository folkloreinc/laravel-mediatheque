<?php

namespace Folklore\Mediatheque\Jobs\Font;

use Folklore\Mediatheque\Support\ShellJob;
use Folklore\Mediatheque\Services\PathFormatter as PathFormatterService;

class WebFonts extends ShellJob
{
    protected $defaultOptions = [
        'formats' => ['ttf', 'otf', 'eot', 'woff', 'woff2', 'svg']
    ];

    protected function getLocalFilePath($file)
    {
        if (isset($this->localFilePath)) {
            return $this->localFilePath;
        }

        $path = parent::getLocalFilePath($file);
        $destinationPath = $this->formatDestinationPath($path);
        app('files')->copy($path, $destinationPath);
        $this->localFilePath = $destinationPath;
        return $this->localFilePath;
    }

    protected function bin()
    {
        return config('mediatheque.services.convertFonts.bin');
    }

    protected function arguments()
    {
        $path = $this->getLocalFilePath($this->file);
        return [$path];
    }

    protected function getOutputFromProcess($process)
    {
        $path = $this->getLocalFilePath($this->file);
        $files = [];
        $formats = data_get($this->options, 'formats', []);
        $pathParts = pathinfo($path);
        $pathFormatter = app(PathFormatterService::class);
        $filesystem = app('files');
        foreach ($formats as $format) {
            $filePath = $pathFormatter->formatPath('{dirname}/{filename}.{format}', $pathParts, [
                'format' => $format,
            ]);
            if ($filesystem->exists($filePath)) {
                $files[$format] = $this->makeFileFromPath($filePath);
            }
        }
        return $files;
    }
}
