<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;

/**
 * Extracts frames of a video as JPEG images, upright and fitted in
 * `max_width` and `max_height`.
 */
abstract class VideoFrames
{
    public function __construct(
        protected readonly Ffmpeg $ffmpeg,
    ) {}

    /**
     * Write the frame at this time. A time past the end falls back to the
     * first frame.
     */
    protected function frame(OperationContext $context, float $time, string $output): void
    {
        $filter = (string) Ffmpeg::fit(
            Ffmpeg::intOption($context, 'max_width', 1920),
            Ffmpeg::intOption($context, 'max_height', null),
        );

        foreach ($time > 0 ? [$time, 0.0] : [0.0] as $at) {
            $this->ffmpeg->run([
                '-ss', sprintf('%.3F', $at),
                '-i', $context->input,
                '-frames:v', '1',
                '-vf', $filter,
                '-q:v', '2',
                $output,
            ], $context);

            if (is_file($output) && filesize($output) > 0) {
                return;
            }
        }

        throw OperationFailed::because(TranscoderErrorCode::UnsupportedInput, 'The video has no frame to extract.');
    }
}
