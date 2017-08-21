<?php

namespace Folklore\Mediatheque\Files;

use Folklore\Mediatheque\Contracts\FilesCreator;
use Folklore\Mediatheque\Contracts\TypeGetter;
use Illuminate\Support\Facades\Log;

class Thumbnails implements FilesCreator
{
    protected $options;

    public function __construct($options = null)
    {
        $this->options = $options;
    }

    public function getKeysOfFilesToCreate($file)
    {
        $path = $file->getRealPath();

        $type = $this->getTypeFromSource($path);
        if (empty($type)) {
            throw new Exception('Can\'t find type of '.$path);
        }

        if (isset($this->options) &&
            isset($this->options['countHandler']) &&
            is_callable($this->options['countHandler'])
        ) {
            $count = call_user_func($this->options['countHandler']);
        } else {
            $count = config('mediatheque.thumbnails.'.$type.'.count', 1);
        }

        // Should create $count thumbnails with 0-based index
        return range(0, $count - 1);
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
            $type = $this->getTypeFromSource($path);
            if (empty($type)) {
                throw new Exception('Can\'t find type of '.$path);
            }

            $count = sizeof($keys);

            $files = [];
            for ($i = 0; $i < $count; $i++) {
                if (is_null($keys[$i])) {
                    continue;
                }

                if (isset($this->options) &&
                    isset($this->options['sourcePathHandler']) &&
                    is_callable($this->options['sourcePathHandler'])
                ) {
                    $source = call_user_func($this->options['sourcePathHandler'], $path, $i, $count);
                } else {
                    $source = $path;
                }
                $destination = $this->getThumbnailDestinationPath($path, $i, $count);
                $result = app('mediatheque.services.thumbnail.'.$type)->createThumbnail($source, $destination);
                if ($result) {
                    $files[$keys[$i]] = $result;
                }
            }

            return $files;
        } catch (Exception $e) {
            Log::error($e);
            return null;
        }
    }

    protected function getThumbnailDestinationPath($path, $i, $count)
    {
        $suffix = $count > 1 ? '-'.$i : '';
        return preg_replace('/\.[a-zA-Z0-9]{2,5}$/', '', $path).$suffix;
    }

    protected function getTypeFromSource($source)
    {
        return app(TypeGetter::class)->getType($source);
    }
}
