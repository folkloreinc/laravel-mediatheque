<?php

namespace Folklore\Mediatheque\Jobs\Font;

use Folklore\Mediatheque\Support\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Exception;

class WebFonts extends PipelineJob
{
    protected $defaultOptions = [
        'formats' => ['ttf', 'otf', 'eot', 'woff', 'woff2', 'svg'],
    ];

    public function handle()
    {
        $path = $this->getLocalFilePath($this->file);

        $command = [
            config('mediatheque.services.convertFonts.bin'),
            escapeshellarg($path),
            '2>&1'
        ];

        $output = [];
        $return = 0;
        exec(implode(' ', $command), $output, $return);

        if ($return !== 0) {
            throw new Exception('convertFonts failed return code :'.$return.' '.implode(PHP_EOL, $output));
        }

        $files = [];
        $formats = array_get($this->options, 'formats', []);
        foreach ($formats as $format) {
            $filePath = preg_replace('/\.[a-z0-9]{3,4}$/', '', $path).'.'.$format;
            if (file_exists($filePath)) {
                $newFile = app(FileContract::class);
                $newFile->setFile($filePath);
                $files[$format] = $newFile;
            }
        }

        return $files;
    }
}
