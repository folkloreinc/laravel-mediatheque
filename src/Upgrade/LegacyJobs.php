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
            self::NAMESPACE.'Video\\H264' => ['op' => 'video.transcode', 'codec' => 'h264'],
            self::NAMESPACE.'Video\\WebM' => ['op' => 'video.transcode', 'codec' => 'vp9'],
            self::NAMESPACE.'Video\\HEVC' => ['op' => 'video.transcode', 'codec' => 'hevc'],
            self::NAMESPACE.'Video\\HLS' => ['op' => 'video.hls'],
            self::NAMESPACE.'Video\\Thumbnails' => [
                'op' => 'video.thumbnails',
                'count' => is_int($options['count'] ?? null) ? $options['count'] : 1,
                'role' => 'thumbnail',
            ],
            self::NAMESPACE.'Audio\\Mp3' => ['op' => 'audio.transcode', 'codec' => 'mp3'],
            self::NAMESPACE.'Audio\\Thumbnails' => array_filter([
                'op' => 'audio.waveform',
                'width' => is_int($options['width'] ?? null) ? $options['width'] : null,
                'height' => is_int($options['height'] ?? null) ? $options['height'] : null,
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
