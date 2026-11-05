<?php

namespace Folklore\Mediatheque\Upgrade;

use Folklore\Mediatheque\Transcoders\Ffmpeg\FfmpegTranscoder;

/**
 * The jobs of a 1.x pipeline, as 2.0 renditions. A 1.x job is a class with
 * options, `H264::class` or `['job' => Thumbnails::class, 'count' => 5]`; a
 * 2.0 rendition is an operation in Eclair's vocabulary with options
 * (docs/decisions/0011).
 *
 * The package's own jobs have an operation. Custom jobs (subclasses of
 * `Support\PipelineJob`), `WebFonts` and `MediaConvert` have none: a 1.x job
 * becomes a custom operation of the `ffmpeg` transcoder (docs/upgrade.md).
 */
final class LegacyJobs
{
    /**
     * The operation that stands for a job without one in the snapshot of a
     * 1.x run: the run keeps its history, and the class it ran.
     */
    public const string LEGACY_OPERATION = 'v1.job';

    private const string NAMESPACE = 'Folklore\\Mediatheque\\Jobs\\';

    /**
     * The class of a job definition, as 1.x wrote it in its config, its
     * serialized pipelines and its `pipelines_jobs` rows.
     */
    public static function jobClass(mixed $job): ?string
    {
        $class = is_array($job) ? ($job['job'] ?? null) : $job;

        return is_string($class) && $class !== '' ? ltrim($class, '\\') : null;
    }

    /**
     * Whether a job is one of the package's own.
     */
    public static function isPackageJob(string $class): bool
    {
        return str_starts_with($class, self::NAMESPACE);
    }

    /**
     * The rendition of a 1.x job, or null when it has no 2.0 operation.
     *
     * @return array<string, mixed>|null
     */
    public static function rendition(mixed $job): ?array
    {
        $class = self::jobClass($job);
        $options = is_array($job) ? $job : [];

        $rendition = match ($class) {
            self::NAMESPACE.'Video\\H264' => ['op' => 'video.transcode', 'codec' => 'h264', ...self::videoOptions($options)],
            self::NAMESPACE.'Video\\WebM' => ['op' => 'video.transcode', 'codec' => 'vp9', ...self::videoOptions($options)],
            self::NAMESPACE.'Video\\HEVC' => ['op' => 'video.transcode', 'codec' => 'hevc', ...self::videoOptions($options)],
            self::NAMESPACE.'Video\\HLS' => ['op' => 'video.hls'],
            self::NAMESPACE.'Video\\Thumbnails' => [
                'op' => 'video.thumbnails',
                'count' => is_int($options['count'] ?? null) ? $options['count'] : 1,
                'role' => 'thumbnail',
            ],
            self::NAMESPACE.'Audio\\Mp3' => ['op' => 'audio.transcode', 'codec' => 'mp3'],
            self::NAMESPACE.'Audio\\Thumbnails' => array_filter([
                'op' => 'audio.waveform',
                'width' => self::positiveInt($options['width'] ?? null),
                'height' => self::positiveInt($options['height'] ?? null),
                'color' => is_string($options['color'] ?? null) ? '#'.ltrim($options['color'], '#') : null,
                'role' => 'thumbnail',
            ], fn (mixed $value) => $value !== null),
            self::NAMESPACE.'Document\\Thumbnails' => array_filter([
                'op' => 'document.thumbnails',
                // 1.x rendered every page by default, 2.0 the first
                'count' => is_int($options['count'] ?? null) ? $options['count'] : 'all',
                'resolution' => is_int($options['resolution'] ?? null) && $options['resolution'] !== 150 ? $options['resolution'] : null,
                'format' => is_string($options['format'] ?? null) && ! in_array($options['format'], ['jpeg', 'jpg'], true) ? $options['format'] : null,
                'quality' => is_int($options['quality'] ?? null) && $options['quality'] !== 100 ? $options['quality'] : null,
                'role' => 'thumbnail',
            ], fn (mixed $value) => $value !== null),
            default => null,
        };

        if ($rendition === null) {
            return null;
        }

        $from = $options['from_file'] ?? null;
        if (is_string($from) && $from !== '' && $from !== 'original') {
            $rendition['from'] = $from;
        }

        $queue = $options['queue'] ?? null;
        if (is_string($queue) && $queue !== '') {
            $rendition['queue'] = $queue;
        }

        return $rendition;
    }

    /**
     * The options of a 1.x job that its rendition doesn't keep: 2.0 has no
     * option for them. Options that turn something off (null, false), and
     * those that do what 2.0 does already (H.264's AAC audio, WebM's
     * constant quality), are not listed.
     *
     * @return list<string>
     */
    public static function unmappedOptions(mixed $job): array
    {
        $class = self::jobClass($job);
        if (! is_array($job) || $class === null || self::rendition($job) === null) {
            return [];
        }

        $mapped = match ($class) {
            self::NAMESPACE.'Video\\H264', self::NAMESPACE.'Video\\WebM', self::NAMESPACE.'Video\\HEVC' => [
                'max_width' => self::positiveInt($job['max_width'] ?? null) !== null,
                'max_height' => self::positiveInt($job['max_height'] ?? null) !== null,
                'resize' => self::resize($job['resize'] ?? null) !== null,
                'quality' => self::positiveInt($job['quality'] ?? null) !== null,
                'audio_codec' => ($job['audio_codec'] ?? null) === 'aac' && $class !== self::NAMESPACE.'Video\\WebM',
                'passes' => ($job['passes'] ?? null) === 1,
                'kilo_bitrate' => in_array($job['kilo_bitrate'] ?? null, [0, '0'], true) && $class === self::NAMESPACE.'Video\\WebM',
                'extension' => ($job['extension'] ?? null) === ($class === self::NAMESPACE.'Video\\WebM' ? 'webm' : 'mp4'),
            ],
            self::NAMESPACE.'Video\\Thumbnails' => ['count' => is_int($job['count'] ?? null)],
            self::NAMESPACE.'Audio\\Thumbnails' => [
                'width' => self::positiveInt($job['width'] ?? null) !== null,
                'height' => self::positiveInt($job['height'] ?? null) !== null,
                'color' => is_string($job['color'] ?? null),
                'extension' => ($job['extension'] ?? null) === 'png',
            ],
            self::NAMESPACE.'Audio\\Mp3' => ['extension' => ($job['extension'] ?? null) === 'mp3'],
            self::NAMESPACE.'Document\\Thumbnails' => [
                'count' => is_int($job['count'] ?? null) || ($job['count'] ?? null) === 'all',
                'resolution' => is_int($job['resolution'] ?? null),
                'format' => is_string($job['format'] ?? null),
                'quality' => is_int($job['quality'] ?? null),
            ],
            default => [],
        };

        $unmapped = [];
        foreach ($job as $option => $value) {
            if (! is_string($option) || in_array($option, ['job', 'queue', 'from_file'], true) || $value === null || $value === false || ($mapped[$option] ?? false)) {
                continue;
            }
            $unmapped[] = $option;
        }

        return $unmapped;
    }

    /**
     * The size and quality of a 1.x video job, as options of
     * `video.transcode`. 1.x scaled to `resize` (`['720', '-2']`: 720 wide,
     * an even height) and to `max_width` and `max_height` when the video was
     * larger; 2.0 fits in `max_width` and `max_height`, never upscaling. The
     * CRF of 1.x was its `quality`.
     *
     * @param  array<array-key, mixed>  $options
     * @return array<string, int>
     */
    private static function videoOptions(array $options): array
    {
        $maxWidth = self::positiveInt($options['max_width'] ?? null);
        $maxHeight = self::positiveInt($options['max_height'] ?? null);
        [$width, $height] = self::resize($options['resize'] ?? null) ?? [null, null];

        $smallest = fn (?int $a, ?int $b): ?int => $a === null ? $b : ($b === null ? $a : min($a, $b));

        return array_filter([
            'max_width' => $smallest($maxWidth, $width),
            'max_height' => $smallest($maxHeight, $height),
            'crf' => self::positiveInt($options['quality'] ?? null),
        ], fn (?int $value) => $value !== null);
    }

    /**
     * The width and height of a 1.x `resize` (`scale=W:H`): a size in
     * pixels, or -1 and -2 for the side that keeps the ratio. Null when it
     * is something else, an expression for instance.
     *
     * @return array{?int, ?int}|null
     */
    private static function resize(mixed $resize): ?array
    {
        if (! is_array($resize)) {
            return null;
        }

        $sides = [];
        foreach ([0, 1] as $index) {
            $value = $resize[$index] ?? null;
            $size = self::positiveInt($value);
            if ($size === null && ! in_array($value, [null, -1, -2, '-1', '-2'], true)) {
                return null;
            }
            $sides[] = $size;
        }

        return $sides[0] === null && $sides[1] === null ? null : [$sides[0], $sides[1]];
    }

    /**
     * A size or a quality of 1.x, which the config often wrote as a string.
     */
    private static function positiveInt(mixed $value): ?int
    {
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }

        return is_int($value) && $value > 0 ? $value : null;
    }

    /**
     * The rendition of a 1.x job in the snapshot of a converted run: its
     * operation, or the legacy operation with the class and the options it
     * ran with.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(mixed $job): array
    {
        $rendition = self::rendition($job);

        if ($rendition !== null) {
            return $rendition;
        }

        $options = [];
        foreach (is_array($job) ? $job : [] as $key => $value) {
            if (is_string($key) && ! in_array($key, ['job', 'name', 'op', 'when', 'keep', 'role'], true) && (is_scalar($value) || is_array($value) || $value === null)) {
                $options[$key] = $value;
            }
        }

        return [...$options, 'op' => self::LEGACY_OPERATION, 'job' => self::jobClass($job), 'from' => null, 'queue' => null];
    }

    /**
     * Whether the `ffmpeg` transcoder can produce a rendition.
     *
     * @param  array<string, mixed>  $rendition
     */
    public static function runsOnFfmpeg(array $rendition): bool
    {
        $operation = $rendition['op'] ?? null;

        if (! is_string($operation) || ! isset(FfmpegTranscoder::OPERATIONS[$operation])) {
            return false;
        }

        return $operation !== 'video.transcode' || in_array($rendition['codec'] ?? 'h264', ['h264', 'vp9'], true);
    }
}
