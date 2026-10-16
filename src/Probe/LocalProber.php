<?php

namespace Folklore\Mediatheque\Probe;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Prober;
use Folklore\Mediatheque\Enums\ProbeKind;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Support\Binaries;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Transcoders\TranscoderError;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Imagick;
use ImagickException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use SimpleXMLElement;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Probes files in the application, with ffprobe for video and audio, and
 * Imagick or `getimagesize()` for images (docs/decisions/0012). It always
 * answers at once: it never returns a pending outcome.
 *
 * - Width and height are the displayed size, after the rotation of a video
 *   or the EXIF orientation of an image; `rotation` is the video's.
 * - ffprobe reads a file on a remote disk through a temporary URL when the
 *   disk signs them, so only what it needs is downloaded. Images are copied
 *   locally.
 * - A file the tools can't read fails with `input_unreadable`. A missing
 *   binary throws (Binaries), so the job is retried once the server is
 *   fixed.
 */
class LocalProber implements Prober
{
    public function __construct(
        private readonly MediathequeConfig $config,
        private readonly LocalFiles $files,
        private readonly Filesystems $filesystems,
        private readonly Binaries $binaries,
    ) {}

    public function probe(File $file): ProbeOutcome
    {
        $source = $file->source();
        $path = $file->path();
        $mime = strtolower($file->mime() ?? 'application/octet-stream');

        if ($source === null || $path === null) {
            return ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::InputUnreadable, 'The file is not stored on a disk.'));
        }

        $kind = self::kindOf($mime);

        if ($kind !== ProbeKind::Video && $kind !== ProbeKind::Audio && $kind !== ProbeKind::Image) {
            return ProbeOutcome::done([MetadataKey::Kind->value => $kind->value, MetadataKey::Mime->value => $mime]);
        }

        $url = $kind !== ProbeKind::Image ? $this->remoteInput($source, $path) : null;
        if ($url !== null) {
            return $this->probePath($url, $mime);
        }

        $copy = $this->files->copy($source, $path);

        try {
            return $this->probePath($copy->path, $mime);
        } finally {
            $copy->cleanup();
        }
    }

    /**
     * Probe a local file, or a URL that ffprobe reads, of this mime.
     */
    public function probePath(string $input, string $mime): ProbeOutcome
    {
        $mime = strtolower($mime);
        $kind = self::kindOf($mime);
        $base = [MetadataKey::Kind->value => $kind->value, MetadataKey::Mime->value => $mime];

        return match ($kind) {
            ProbeKind::Video, ProbeKind::Audio => $this->probeMedia($input, $base),
            ProbeKind::Image => $this->probeImage($input, $mime, $base),
            default => ProbeOutcome::done($base),
        };
    }

    /**
     * The probe kind of a mime detected from the content.
     */
    public static function kindOf(string $mime): ProbeKind
    {
        return match (true) {
            str_starts_with($mime, 'video/') => ProbeKind::Video,
            str_starts_with($mime, 'audio/') => ProbeKind::Audio,
            str_starts_with($mime, 'image/') => ProbeKind::Image,
            $mime === 'application/pdf' => ProbeKind::Document,
            str_starts_with($mime, 'font/') || Str::startsWith($mime, ['application/font-', 'application/x-font-']) || $mime === 'application/vnd.ms-opentype' => ProbeKind::Font,
            default => ProbeKind::Other,
        };
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function probeMedia(string $input, array $base): ProbeOutcome
    {
        $process = new Process([
            $this->binaries->path('ffprobe'),
            '-v', 'error',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $input,
        ]);
        $process->setTimeout($this->config->probeTimeout());

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::Timeout, sprintf('ffprobe took longer than %d seconds.', $this->config->probeTimeout())));
        }

        if (! $process->isSuccessful()) {
            return ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::InputUnreadable, 'ffprobe can\'t read the file: '.Str::limit(trim($process->getErrorOutput()), 500)));
        }

        $output = json_decode($process->getOutput(), true);

        return is_array($output)
            ? self::fromFfprobe($output, $base)
            : ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::InputUnreadable, 'ffprobe gave no metadata.'));
    }

    /**
     * A URL ffprobe reads a remote file from, so it only downloads what it
     * needs. Null for a local disk, or a disk that doesn't sign URLs.
     */
    private function remoteInput(string $disk, string $path): ?string
    {
        $filesystem = $this->filesystems->disk($disk);

        if (! $filesystem instanceof FilesystemAdapter || $filesystem->getAdapter() instanceof LocalFilesystemAdapter || ! $filesystem->providesTemporaryUrls()) {
            return null;
        }

        return $filesystem->temporaryUrl($path, Date::now()->addSeconds($this->config->probeTimeout() + 60));
    }

    /**
     * @param  array<array-key, mixed>  $output
     * @param  array<string, mixed>  $base
     */
    private static function fromFfprobe(array $output, array $base): ProbeOutcome
    {
        $streams = is_array($output['streams'] ?? null) ? array_filter($output['streams'], 'is_array') : [];
        $format = is_array($output['format'] ?? null) ? $output['format'] : [];

        $video = null;
        $audio = null;
        foreach ($streams as $stream) {
            $type = $stream['codec_type'] ?? null;
            $attached = is_array($stream['disposition'] ?? null) && ($stream['disposition']['attached_pic'] ?? 0) === 1;

            if ($type === 'video' && ! $attached && $video === null) {
                $video = $stream;
            } elseif ($type === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        if ($video === null && $audio === null) {
            return ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::UnsupportedInput, 'The file has no video or audio stream.'));
        }

        $metadata = [
            ...$base,
            MetadataKey::Kind->value => ($video !== null ? ProbeKind::Video : ProbeKind::Audio)->value,
            MetadataKey::Duration->value => self::float($format['duration'] ?? null) ?? self::float(($video ?? $audio)['duration'] ?? null),
            MetadataKey::Bitrate->value => self::int($format['bit_rate'] ?? null),
        ];

        if ($video !== null) {
            $rotation = self::rotation($video);
            $width = self::int($video['width'] ?? null);
            $height = self::int($video['height'] ?? null);
            $turned = $rotation === 90 || $rotation === 270;

            $metadata += [
                MetadataKey::Width->value => $turned ? $height : $width,
                MetadataKey::Height->value => $turned ? $width : $height,
                MetadataKey::Rotation->value => $rotation,
                MetadataKey::FrameRate->value => self::frameRate($video['avg_frame_rate'] ?? null) ?? self::frameRate($video['r_frame_rate'] ?? null),
                MetadataKey::VideoCodec->value => is_string($video['codec_name'] ?? null) ? $video['codec_name'] : null,
            ];
        }

        if ($audio !== null) {
            $metadata += [
                MetadataKey::AudioCodec->value => is_string($audio['codec_name'] ?? null) ? $audio['codec_name'] : null,
                MetadataKey::AudioChannels->value => self::int($audio['channels'] ?? null),
            ];
        }

        return ProbeOutcome::done(array_filter($metadata, fn (mixed $value) => $value !== null));
    }

    /**
     * Degrees to turn the video clockwise to display it: 0, 90, 180 or 270.
     *
     * @param  array<array-key, mixed>  $stream
     */
    private static function rotation(array $stream): int
    {
        $rotation = null;

        foreach (is_array($stream['side_data_list'] ?? null) ? $stream['side_data_list'] : [] as $data) {
            if (is_array($data) && isset($data['rotation']) && is_numeric($data['rotation'])) {
                // The display matrix gives the counterclockwise rotation
                $rotation = -(int) $data['rotation'];
            }
        }

        $tags = is_array($stream['tags'] ?? null) ? $stream['tags'] : [];
        if ($rotation === null && isset($tags['rotate']) && is_numeric($tags['rotate'])) {
            $rotation = (int) $tags['rotate'];
        }

        $rotation = (($rotation ?? 0) % 360 + 360) % 360;

        return (int) (round($rotation / 90) * 90) % 360;
    }

    private static function frameRate(mixed $value): ?float
    {
        if (! is_string($value) || preg_match('#^(\d+)/(\d+)$#', $value, $matches) !== 1 || (int) $matches[2] === 0 || (int) $matches[1] === 0) {
            return null;
        }

        return round((int) $matches[1] / (int) $matches[2], 3);
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function probeImage(string $path, string $mime, array $base): ProbeOutcome
    {
        if ($mime === 'image/svg+xml') {
            return ProbeOutcome::done([...$base, ...self::svgSize($path)]);
        }

        $metadata = class_exists(Imagick::class) ? self::imagick($path) : self::getImageSize($path);

        return $metadata !== null
            ? ProbeOutcome::done([...$base, ...$metadata])
            : ProbeOutcome::failed(new TranscoderError(TranscoderErrorCode::InputUnreadable, 'The image can\'t be read.'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function imagick(string $path): ?array
    {
        try {
            $image = new Imagick;
            $image->pingImage($path);
            $image->setFirstIterator();

            $width = $image->getImageWidth();
            $height = $image->getImageHeight();
            $turned = in_array($image->getImageOrientation(), [5, 6, 7, 8], true);
            $frames = $image->getNumberImages();
            $image->clear();

            return [
                MetadataKey::Width->value => $turned ? $height : $width,
                MetadataKey::Height->value => $turned ? $width : $height,
                MetadataKey::Animated->value => $frames > 1,
            ];
        } catch (ImagickException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function getImageSize(string $path): ?array
    {
        $size = @getimagesize($path);
        if ($size === false) {
            return null;
        }

        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;
        $turned = in_array($orientation, [5, 6, 7, 8], true);

        return [
            MetadataKey::Width->value => $turned ? $size[1] : $size[0],
            MetadataKey::Height->value => $turned ? $size[0] : $size[1],
        ];
    }

    /**
     * The size an SVG declares, from its width and height or its viewBox.
     *
     * @return array<string, int>
     */
    private static function svgSize(string $path): array
    {
        $previous = libxml_use_internal_errors(true);
        $svg = simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($svg === false) {
            return [];
        }

        $width = self::length((string) ($svg['width'] ?? ''));
        $height = self::length((string) ($svg['height'] ?? ''));

        if (($width === null || $height === null) && preg_match('/^\s*[-\d.]+[\s,]+[-\d.]+[\s,]+([\d.]+)[\s,]+([\d.]+)\s*$/', (string) ($svg['viewBox'] ?? ''), $matches) === 1) {
            $width ??= (int) round((float) $matches[1]);
            $height ??= (int) round((float) $matches[2]);
        }

        return array_filter([
            MetadataKey::Width->value => $width,
            MetadataKey::Height->value => $height,
        ], fn (?int $value) => $value !== null && $value > 0);
    }

    private static function length(string $value): ?int
    {
        return preg_match('/^\s*([\d.]+)\s*(px)?\s*$/', $value, $matches) === 1 ? (int) round((float) $matches[1]) : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private static function float(mixed $value): ?float
    {
        return is_numeric($value) ? round((float) $value, 3) : null;
    }
}
