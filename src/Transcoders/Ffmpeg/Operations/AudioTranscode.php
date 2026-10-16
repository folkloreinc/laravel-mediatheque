<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;

/**
 * `audio.transcode`: web audio, `codec: mp3` (default, VBR quality 2) or
 * `aac` (M4A, `bitrate` 128k by default).
 */
class AudioTranscode implements Operation
{
    public function __construct(
        private readonly Ffmpeg $ffmpeg,
    ) {}

    public function extension(Rendition $rendition): string
    {
        return self::codec($rendition) === 'aac' ? 'm4a' : 'mp3';
    }

    public function run(OperationContext $context): array
    {
        $codec = self::codec($context->rendition);
        $output = $context->outputDirectory.'/output.'.$this->extension($context->rendition);
        $bitrate = $context->option('bitrate', '128k');

        $audio = match ($codec) {
            'mp3' => ['-c:a', 'libmp3lame', '-q:a', '2'],
            'aac' => ['-c:a', 'aac', '-b:a', is_string($bitrate) ? $bitrate : '128k', '-movflags', '+faststart'],
            default => throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('The codec "%s" of the rendition "%s" is not supported: use mp3 or aac.', $codec, $context->rendition->name)),
        };

        $this->ffmpeg->run(['-i', $context->input, '-vn', '-map', '0:a:0', ...$audio, $output], $context, reportsProgress: true);

        return [new LocalOutput($output, $codec === 'aac' ? 'audio/mp4' : 'audio/mpeg')];
    }

    private static function codec(Rendition $rendition): string
    {
        $codec = $rendition->options['codec'] ?? 'mp3';

        return is_string($codec) ? strtolower($codec) : 'mp3';
    }
}
