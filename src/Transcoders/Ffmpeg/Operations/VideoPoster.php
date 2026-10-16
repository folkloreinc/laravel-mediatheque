<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;

/**
 * `video.poster`: one JPEG frame, at `time` seconds, or at a tenth of the
 * video (10 seconds at most) by default.
 */
class VideoPoster extends VideoFrames implements Operation
{
    public function extension(Rendition $rendition): string
    {
        return 'jpg';
    }

    public function run(OperationContext $context): array
    {
        $time = $context->option('time');
        $output = $context->outputDirectory.'/poster.jpg';

        $this->frame($context, is_int($time) || is_float($time) ? (float) $time : min(10.0, ($context->duration() ?? 0.0) / 10), $output);

        return [new LocalOutput($output, 'image/jpeg')];
    }
}
