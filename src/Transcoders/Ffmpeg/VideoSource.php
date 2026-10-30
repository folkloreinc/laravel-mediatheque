<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

/**
 * What `video.transcode` reads from its input with ffprobe: the size of the
 * frame as displayed, and what decides whether its streams can be copied
 * instead of encoded (`passthrough`), as Eclair's operation does.
 *
 * @internal
 */
final readonly class VideoSource
{
    /**
     * H.264 profiles that every browser plays. Plain Baseline may use
     * features browsers lack.
     */
    private const array WEB_H264_PROFILES = ['Constrained Baseline', 'Main', 'High'];

    /** Level 4.2, as ffprobe reports it: 1080p at 60 fps. */
    private const int WEB_H264_MAX_LEVEL = 42;

    /**
     * The maximum video bitrate of H.264 on the web, in kbit/s, by the
     * number of pixels of a frame, at up to 30 fps.
     */
    private const array WEB_BITRATES = [
        [640 * 360, 1000],
        [960 * 540, 2000],
        [1280 * 720, 3000],
        [1920 * 1080, 6000],
        [2560 * 1440, 10000],
    ];

    /**
     * @param  int  $width  As displayed, once rotated
     * @param  int  $height  As displayed, once rotated
     * @param  int|null  $level  `40` for 4.0
     * @param  int|null  $bitrate  The video's average, in bit/s, or the whole file's when the container doesn't say
     * @param  bool  $plain  Upright, progressive, with square pixels
     * @param  int|null  $audioBitrate  In bit/s
     */
    public function __construct(
        public int $width,
        public int $height,
        public ?float $frameRate = null,
        public ?string $codec = null,
        public ?string $profile = null,
        public ?int $level = null,
        public ?string $pixelFormat = null,
        public ?int $bitrate = null,
        public bool $plain = true,
        public ?string $audioCodec = null,
        public ?int $audioChannels = null,
        public ?int $audioBitrate = null,
    ) {}

    /**
     * From the JSON of `ffprobe -show_format -show_streams`. Null without a
     * video stream.
     *
     * @param  array<array-key, mixed>  $probe
     */
    public static function fromFfprobe(array $probe): ?self
    {
        $streams = array_values(array_filter(is_array($probe['streams'] ?? null) ? $probe['streams'] : [], 'is_array'));
        $video = null;
        $audio = null;
        foreach ($streams as $stream) {
            if ($video === null && ($stream['codec_type'] ?? null) === 'video' && data_get($stream, 'disposition.attached_pic') !== 1) {
                $video = $stream;
            }
            if ($audio === null && ($stream['codec_type'] ?? null) === 'audio') {
                $audio = $stream;
            }
        }
        if ($video === null || ! is_int($video['width'] ?? null) || ! is_int($video['height'] ?? null)) {
            return null;
        }

        $rotation = 0;
        foreach (is_array($video['side_data_list'] ?? null) ? $video['side_data_list'] : [] as $data) {
            if (is_array($data) && is_numeric($data['rotation'] ?? null)) {
                $rotation = (int) round((float) $data['rotation']);
            }
        }
        $quarterTurn = abs((int) round($rotation / 90)) % 2 === 1;
        $sampleAspectRatio = $video['sample_aspect_ratio'] ?? '1:1';
        $fieldOrder = $video['field_order'] ?? 'unknown';

        return new self(
            width: $quarterTurn ? $video['height'] : $video['width'],
            height: $quarterTurn ? $video['width'] : $video['height'],
            frameRate: self::rate($video['avg_frame_rate'] ?? null),
            codec: self::string($video['codec_name'] ?? null),
            profile: self::string($video['profile'] ?? null),
            level: is_int($video['level'] ?? null) ? $video['level'] : null,
            pixelFormat: self::string($video['pix_fmt'] ?? null),
            bitrate: self::integer($video['bit_rate'] ?? null) ?? self::integer(data_get($probe, 'format.bit_rate')),
            plain: $rotation % 360 === 0
                && in_array($sampleAspectRatio, ['1:1', '0:1', 'N/A'], true)
                && in_array($fieldOrder, ['progressive', 'unknown'], true),
            audioCodec: self::string($audio['codec_name'] ?? null),
            audioChannels: is_int($audio['channels'] ?? null) ? $audio['channels'] : null,
            audioBitrate: self::integer($audio['bit_rate'] ?? null),
        );
    }

    /**
     * The size of the output: fitted in the maximum size, never larger,
     * with even sides.
     *
     * @return array{int, int}
     */
    public function fit(?int $maxWidth, ?int $maxHeight): array
    {
        $scale = min(
            1,
            $maxWidth === null ? 1 : $maxWidth / $this->width,
            $maxHeight === null ? 1 : $maxHeight / $this->height,
        );

        return [self::even($this->width * $scale), self::even($this->height * $scale)];
    }

    /**
     * The maximum video bitrate of H.264 on the web for an output of this
     * size, in kbit/s: 3000 for 720p, 6000 for 1080p, half again above
     * 30 fps (docs/decisions/0026).
     */
    public function webBitrate(int $width, int $height): int
    {
        $kbps = 16000;
        foreach (self::WEB_BITRATES as [$pixels, $bitrate]) {
            if ($width * $height <= $pixels) {
                $kbps = $bitrate;
                break;
            }
        }

        return ($this->frameRate ?? 30) > 31 ? (int) round($kbps * 1.5) : $kbps;
    }

    /**
     * Why the video stream can't be copied into an H.264 MP4 of at most
     * this size and bitrate (kbit/s), or null if it can.
     */
    public function passthroughRefusal(?int $maxWidth, ?int $maxHeight, int $maxBitrate): ?string
    {
        return match (true) {
            $this->codec !== 'h264' => sprintf('the video is %s, not h264', $this->codec ?? 'unknown'),
            ! in_array($this->pixelFormat, ['yuv420p', 'yuvj420p'], true) => sprintf('its pixel format is %s, not yuv420p', $this->pixelFormat ?? 'unknown'),
            ! in_array($this->profile, self::WEB_H264_PROFILES, true) => sprintf('its profile is %s', $this->profile ?? 'unknown'),
            $this->level === null || $this->level > self::WEB_H264_MAX_LEVEL => sprintf('its level is %s', $this->level === null ? 'unknown' : $this->level / 10),
            ! $this->plain => 'it is rotated, interlaced or has non-square pixels',
            $this->fit($maxWidth, $maxHeight) !== [$this->width, $this->height] => 'it is larger than the maximum size, or its sides are odd',
            $this->bitrate === null || $this->bitrate > $maxBitrate * 1000 => sprintf('its bitrate is above %d kbit/s, or unknown', $maxBitrate),
            default => null,
        };
    }

    /**
     * Whether the audio can be copied into an MP4 encoded at this bitrate
     * (kbit/s): AAC, mono or stereo, at most twice the bitrate.
     */
    public function copiesAudio(int $audioBitrate): bool
    {
        return $this->audioCodec === 'aac'
            && ($this->audioChannels ?? 3) <= 2
            && $this->audioBitrate !== null
            && $this->audioBitrate <= $audioBitrate * 2000;
    }

    private static function even(float $value): int
    {
        return max(2, (int) floor($value / 2) * 2);
    }

    private static function rate(mixed $value): ?float
    {
        if (! is_string($value) || preg_match('/^(\d+)\/(\d+)$/', $value, $match) !== 1 || (int) $match[1] === 0 || (int) $match[2] === 0) {
            return null;
        }

        return (int) $match[1] / (int) $match[2];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
