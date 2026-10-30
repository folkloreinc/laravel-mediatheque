<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg\Operations;

use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Ffmpeg;
use Folklore\Mediatheque\Transcoders\Ffmpeg\LocalOutput;
use Folklore\Mediatheque\Transcoders\Ffmpeg\Operation;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationContext;
use Folklore\Mediatheque\Transcoders\Ffmpeg\OperationFailed;
use Folklore\Mediatheque\Transcoders\Ffmpeg\VideoSource;

/**
 * `video.transcode`: a web video. The frame is turned upright, fitted in
 * `max_width` and `max_height` (never upscaled), and the audio, if any, is
 * kept in stereo.
 *
 * - `codec: h264` (default): MP4 with H.264 (High profile) and AAC,
 *   `+faststart` so it plays while it downloads, and a keyframe every
 *   4 seconds at most for seeking. Options: `crf` (23), `preset`
 *   (`medium`), `max_bitrate` in kbit/s (by default from the output's size:
 *   3000 for 720p, 6000 for 1080p, half again above 30 fps).
 * - `codec: vp9`: WebM with VP9 and Opus. Options: `crf` (32), `max_bitrate`
 *   (none: constant quality).
 * - `audio_bitrate`: `128k` by default, `96k` for VP9.
 * - `passthrough: true`: an H.264 input that already fits (8-bit 4:2:0, a
 *   web profile up to level 4.2, upright, within the size and the maximum
 *   bitrate) is copied instead of encoded, with its audio when it is AAC in
 *   mono or stereo at most twice `audio_bitrate`. The file's metadata says
 *   what was copied: `video` and `audio`, `copied` or `encoded`.
 *
 * The defaults and `passthrough` are those of Eclair's `video.transcode`
 * (docs/decisions/0026).
 */
class VideoTranscode implements Operation
{
    /** Seconds between keyframes of H.264. */
    private const int KEYFRAME_INTERVAL = 4;

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
        if (! in_array($codec, ['h264', 'vp9'], true)) {
            throw OperationFailed::because(TranscoderErrorCode::OperationFailed, sprintf('The codec "%s" of the rendition "%s" is not supported: use h264 or vp9.', $codec, $context->rendition->name));
        }

        $source = VideoSource::fromFfprobe($this->ffmpeg->probe($context->input, $context))
            ?? throw OperationFailed::because(TranscoderErrorCode::UnsupportedInput, 'The file has no video stream.');
        $output = $context->outputDirectory.'/output.'.$this->extension($context->rendition);
        $maxWidth = Ffmpeg::intOption($context, 'max_width', null);
        $maxHeight = Ffmpeg::intOption($context, 'max_height', null);
        [$width, $height] = $source->fit($maxWidth, $maxHeight);
        $maxBitrate = Ffmpeg::intOption($context, 'max_bitrate', $codec === 'h264' ? $source->webBitrate($width, $height) : null);
        $audioBitrate = self::kilobits($context->option('audio_bitrate')) ?? ($codec === 'vp9' ? 96 : 128);
        $hasAudio = $source->audioCodec !== null;

        $copiesVideo = $codec === 'h264'
            && $context->option('passthrough', false) === true
            && $maxBitrate !== null
            && $source->passthroughRefusal($maxWidth, $maxHeight, $maxBitrate) === null;
        $copiesAudio = $copiesVideo && $hasAudio && $source->copiesAudio($audioBitrate);

        $video = $copiesVideo
            ? ['-c:v', 'copy', '-tag:v', 'avc1']
            : ['-vf', (string) Ffmpeg::fit($maxWidth, $maxHeight), ...$this->encoder($context, $codec, $source, $maxBitrate)];
        $audio = $copiesAudio
            ? ['-c:a', 'copy']
            : ['-c:a', $codec === 'vp9' ? 'libopus' : 'aac', '-b:a', $audioBitrate.'k', '-ac', '2'];

        $this->ffmpeg->run([
            '-i', $context->input,
            '-map', '0:v:0',
            '-map', '0:a:0?',
            ...$video,
            ...$audio,
            ...($codec === 'h264' ? ['-movflags', '+faststart'] : []),
            $output,
        ], $context, reportsProgress: true);

        return [new LocalOutput($output, $codec === 'vp9' ? 'video/webm' : 'video/mp4', metadata: [
            'video' => $copiesVideo ? 'copied' : 'encoded',
            ...($hasAudio ? ['audio' => $copiesAudio ? 'copied' : 'encoded'] : []),
        ])];
    }

    /**
     * @return list<string>
     */
    private function encoder(OperationContext $context, string $codec, VideoSource $source, ?int $maxBitrate): array
    {
        $crf = (string) Ffmpeg::intOption($context, 'crf', $codec === 'vp9' ? 32 : 23);

        if ($codec === 'vp9') {
            // libvpx needs a bitrate of 0 for constant quality.
            return ['-c:v', 'libvpx-vp9', '-crf', $crf, '-b:v', $maxBitrate === null ? '0' : $maxBitrate.'k', '-row-mt', '1', '-pix_fmt', 'yuv420p'];
        }

        $preset = $context->option('preset', 'medium');

        return [
            '-c:v', 'libx264',
            '-preset', is_string($preset) ? $preset : 'medium',
            '-crf', $crf,
            '-pix_fmt', 'yuv420p',
            '-profile:v', 'high',
            '-g', (string) (int) round(self::KEYFRAME_INTERVAL * ($source->frameRate ?? 25)),
            ...($maxBitrate === null ? [] : ['-maxrate', $maxBitrate.'k', '-bufsize', ($maxBitrate * 2).'k']),
        ];
    }

    private static function codec(Rendition $rendition): string
    {
        $codec = $rendition->options['codec'] ?? 'h264';

        return is_string($codec) ? strtolower($codec) : 'h264';
    }

    /** `'128k'` and `128` are 128. */
    private static function kilobits(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        return is_string($value) && preg_match('/^(\d+)k$/i', $value, $match) === 1 ? (int) $match[1] : null;
    }
}
