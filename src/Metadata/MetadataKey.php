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

    /** In degrees, clockwise: 0, 90, 180 or 270. A video's. */
    case Rotation = 'rotation';

    /**
     * The EXIF orientation of an image, from 1 to 8, when it declares one.
     * The file is stored as it is: renderers turn it upright, and `width`
     * and `height` are the upright size.
     */
    case Orientation = 'orientation';

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

    /** A ThumbHash image placeholder, in base64. */
    case Thumbhash = 'thumbhash';

    case Scenes = 'scenes';
    case Locale = 'locale';

    /** An embed's platform: `youtube`, `vimeo`… (docs/decisions/0029). */
    case Provider = 'provider';

    /** An embed's id at its provider. */
    case ProviderId = 'provider_id';

    /** The canonical URL of an embed's page. */
    case ProviderUrl = 'provider_url';

    /** The URL of the provider's player, for an iframe. */
    case EmbedUrl = 'embed_url';

    /** The player's HTML, from the provider's oEmbed only. */
    case EmbedHtml = 'embed_html';

    case Title = 'title';
    case Description = 'description';

    /** The provider's id of an embed's author: a YouTube channel, a Vimeo user… */
    case AuthorId = 'author_id';

    case AuthorName = 'author_name';
    case AuthorUrl = 'author_url';

    /** The URL of the provider's thumbnail of an embed. */
    case ThumbnailUrl = 'thumbnail_url';

    /** ISO 8601. */
    case PublishedAt = 'published_at';

    /** Whether the provider lets other sites play the embed. */
    case Embeddable = 'embeddable';

    /** `public`, `unlisted` or `private`. */
    case Privacy = 'privacy';

    /** A live stream, current or upcoming. */
    case Live = 'live';

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
