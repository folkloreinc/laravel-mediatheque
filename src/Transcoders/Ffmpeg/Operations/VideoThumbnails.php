<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;

/**
 * `video.thumbnails`: `count` JPEG frames (5 by default), spread evenly over
 * the video, as a group with indexes from 0.
 */
class VideoThumbnails extends VideoFrames implements Operation
{
    public function extension(Rendition $rendition): string
    {
        return 'jpg';
    }

    public function run(OperationContext $context): array
    {
        $count = (int) Ffmpeg::intOption($context, 'count', 5);
        $duration = $context->duration() ?? 0.0;
        $outputs = [];

        for ($index = 0; $index < $count; $index++) {
            $output = sprintf('%s/thumbnail-%d.jpg', $context->outputDirectory, $index);
            $this->frame($context, $duration * ($index + 0.5) / $count, $output);
            $outputs[] = new LocalOutput($output, 'image/jpeg', $index);
            ($context->progress)(($index + 1) / $count);
        }

        return $outputs;
    }
}
