<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Contracts\Services\Duration;
use Folklore\Mediatheque\Contracts\Support\HasMetadatas;
use Folklore\Mediatheque\Support\ThumbnailsJob;

class Thumbnails extends ThumbnailsJob
{
    protected $type = 'video';

    protected $defaultOptions = [];

    protected $duration;

    protected function getOptions($index = 0)
    {
        $duration = $this->getDuration();
        if (!isset($duration)) {
            return $this->options;
        }

        $count = data_get($this->options, 'count', null);
        $inMiddle = data_get($this->options, 'in_middle', false);
        $time = null;
        if (isset($count)) {
            $steps = $inMiddle ? $duration / ($count + 1) : $duration / $count;
            $time = ($inMiddle ? $index + 1 : $index) * $steps;
        } elseif ($inMiddle) {
            $time = $duration / 2;
        }

        return isset($time)
            ? array_merge(
                [
                    'time' => $time,
                ],
                $this->options
            )
            : $this->options;
    }

    protected function getDuration()
    {
        if (!isset($this->duration) && $this->file instanceof HasMetadatas) {
            $metadata = $this->file->getMetadata('duration');
            $this->duration = isset($metadata) ? $metadata->getValue() : null;
        }
        if (!isset($this->duration) && $this->model instanceof HasMetadatas) {
            $metadata = $this->model->getMetadata('duration');
            $this->duration = isset($metadata) ? $metadata->getValue() : null;
        }
        if (!isset($this->duration)) {
            $localPath = $this->getLocalFilePath($this->file);
            $this->duration = resolve(Duration::class)->getDuration($localPath);
        }
        return $this->duration;
    }
}
