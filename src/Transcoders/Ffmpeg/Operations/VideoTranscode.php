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
 * `video.transcode`: a web video. The frame is turned upright, fitted in
 * `max_width` and `max_height` (never upscaled), and the audio, if any, is
 * kept in stereo.
 *
 * - `codec: h264` (default): MP4 with H.264 and AAC, `+faststart` so it
 *   plays while it downloads. Options: `crf` (23), `preset` (`medium`).
 * - `codec: vp9`: WebM with VP9 and Opus. Option: `crf` (32).
 * - `audio_bitrate`: `128k` by default.
 */
class VideoTranscode implements Operation
{
    public function __construct(
        private readonly Ffmpeg $ffmpeg,
    ) {}

    public function extension(Rendition $rendition): string
    {
        return self::codec($rendition) === 'vp9' ? 'webm' : 'mp4';
    }

    public function run(OperationContext $context): array
    {
        $codec = self::codec($context->rendition);
        $output = $context->outputDirectory.'/output.'.$this->extension($context->rendition);
        $crf = Ffmpeg::intOption($context, 'crf', $codec === 'vp9' ? 32 : 23);
        $audioBitrate = $context->option('audio_bitrate', $codec === 'vp9' ? '96k' : '128k');
        $preset = $context->option('preset', 'medium');

        $video = match ($codec) {
            'h264' => ['-c:v', 'libx264', '-preset', is_string($preset) ? $preset : 'medium', '-crf', (string) $crf, '-pix_fmt', 'yuv420p', '-profile:v', 'high', '-movflags', '+faststart'],
            'vp9' => ['-c:v', 'libvpx-vp9', '-crf', (string) $crf, '-b:v', '0', '-row-mt', '1', '-pix_fmt', 'yuv420p'],
            default => throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('The codec "%s" of the rendition "%s" is not supported: use h264 or vp9.', $codec, $context->rendition->name)),
        };

        $this->ffmpeg->run([
            '-i', $context->input,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            '-vf', (string) Ffmpeg::fit(Ffmpeg::intOption($context, 'max_width', null), Ffmpeg::intOption($context, 'max_height', null)),
            ...$video,
            '-c:a', $codec === 'vp9' ? 'libopus' : 'aac',
            '-b:a', is_string($audioBitrate) ? $audioBitrate : '128k',
            '-ac', '2',
            $output,
        ], $context, reportsProgress: true);

        return [new LocalOutput($output, $codec === 'vp9' ? 'video/webm' : 'video/mp4')];
    }

    private static function codec(Rendition $rendition): string
    {
        $codec = $rendition->options['codec'] ?? 'h264';

        return is_string($codec) ? strtolower($codec) : 'h264';
    }
}
