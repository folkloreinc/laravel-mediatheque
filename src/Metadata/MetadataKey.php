<?php

namespace Folklore\Mediatheque\Metadata;

use Illuminate\Support\Str;

/**
 * The canonical metadata keys (docs/architecture.md#metadata-keys). Every
 * prober and transcoder writes these keys, so metadata means the same thing
 * whatever produced it.
 */
enum MetadataKey: string
{
    /** ProbeKind: image, video, audio, document, font or other. */
    case Kind = 'kind';

    case Mime = 'mime';
    case Extension = 'extension';

    /** In bytes. */
    case Size = 'size';

    /** SHA-256, in hex. */
    case Checksum = 'checksum';

    /** The name the file was ingested with. */
    case Filename = 'filename';

    /** In pixels. */
    case Width = 'width';

    /** In pixels. */
    case Height = 'height';

    /** In degrees, clockwise: 0, 90, 180 or 270. */
    case Rotation = 'rotation';

    /** In seconds. */
    case Duration = 'duration';

    /** In frames per second. */
    case FrameRate = 'frame_rate';

    /** In bits per second. */
    case Bitrate = 'bitrate';

    case VideoCodec = 'video_codec';
    case AudioCodec = 'audio_codec';
    case AudioChannels = 'audio_channels';
    case PagesCount = 'pages_count';
    case FontNames = 'font_names';
    case Animated = 'animated';

    /** A summary, never GPS data. */
    case Exif = 'exif';

    case Palette = 'palette';
    case Blurhash = 'blurhash';
    case Scenes = 'scenes';
    case Locale = 'locale';

    /**
     * Names that drivers use for canonical keys, other than the camel case
     * of the key itself (`frameRate`), which normalize() already converts.
     */
    private const array ALIASES = [
        'page_count' => self::PagesCount,
        'pages' => self::PagesCount,
        'mime_type' => self::Mime,
        'fps' => self::FrameRate,
        'channels' => self::AudioChannels,
    ];

    /**
     * Metadata with canonical keys: a known alias becomes its key, any other
     * key is converted to snake_case. Values are kept as they are.
     *
     * @param  array<array-key, mixed>  $metadata
     * @return array<string, mixed>
     */
    public static function normalize(array $metadata): array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            $normalized[self::normalizeKey((string) $key)] = $value;
        }

        return $normalized;
    }

    public static function normalizeKey(string $key): string
    {
        $snake = Str::snake(str_replace(['-', ' '], '_', $key));
        $snake = (string) preg_replace('/_+/', '_', $snake);

        return (self::ALIASES[$snake] ?? self::tryFrom($snake))->value ?? $snake;
    }
}
