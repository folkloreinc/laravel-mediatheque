<?php

namespace Folklore\Mediatheque\Files;

use Folklore\Mediatheque\Contracts\FilesCreator;
use Illuminate\Support\Facades\Log;
use Exception;

class WebFonts implements FilesCreator
{
    protected $extensions = ['ttf', 'otf', 'eot', 'woff', 'woff2', 'svg'];

    protected $options;

    public function __constructor($options = null)
    {
        $this->options = $options;
    }

    public function getKeysOfFilesToCreate($file)
    {
        // Should create font file for each in $this->extensions
        return $this->extensions;
    }

    /**
     * Create files from path
     *
     * @param  string  $file
     * @param  array   [$keys]
     * @return array   $files
     */
    public function createFiles($file, $keys = null)
    {
        if (is_null($keys)) {
            $keys = $this->getKeysOfFilesToCreate($file);
        }

        $path = $file->getRealPath();
        try {
            $command = [
                config('mediatheque.programs.convertFonts.bin'),
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
            $extensions = $this->extensions;
            foreach ($extensions as $extension) {
                $filePath = preg_replace('/\.[a-z0-9]{3,4}$/', '', $path).'.'.$extension;
                if (file_exists($filePath) && in_array($extension, $keys)) {
                    $files[$extension] = $filePath;
                }
            }

            return $files;
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }
}
