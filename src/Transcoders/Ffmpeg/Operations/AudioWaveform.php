<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;

/**
 * `audio.waveform`: a PNG of the waveform, `width` × `height` (1200 × 240
 * by default), in `color` (`#000000`) on a transparent background.
 */
class AudioWaveform implements Operation
{
    public function __construct(
        private readonly Ffmpeg $ffmpeg,
    ) {}

    public function extension(Rendition $rendition): string
    {
        return 'png';
    }

    public function run(OperationContext $context): array
    {
        $output = $context->outputDirectory.'/waveform.png';
        $color = $context->option('color', '#000000');
        $color = is_string($color) && preg_match('/^#?[0-9A-Fa-f]{6}$/', $color) === 1 ? '0x'.ltrim($color, '#') : '0x000000';

        $this->ffmpeg->run([
            '-i', $context->input,
            '-filter_complex', sprintf(
                'aformat=channel_layouts=mono,showwavespic=s=%dx%d:colors=%s',
                Ffmpeg::intOption($context, 'width', 1200),
                Ffmpeg::intOption($context, 'height', 240),
                $color,
            ),
            '-frames:v', '1',
            $output,
        ], $context);

        return [new LocalOutput($output, 'image/png')];
    }
}
