<?php

namespace Folklore\Mediatheque\Ingest;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

/**
 * Where ingest reads a file from (docs/decisions/0006).
 *
 * The client's file name is kept as the media's name and its `filename`
 * metadata, never to decide its type or extension.
 */
final readonly class Source
{
    /**
     * @param  resource|null  $stream
     */
    private function __construct(
        public SourceKind $kind,
        public ?UploadedFile $upload = null,
        public ?string $path = null,
        public ?string $disk = null,
        public ?string $url = null,
        public mixed $stream = null,
        public ?string $filename = null,
    ) {}

    public static function upload(UploadedFile $file): self
    {
        return new self(SourceKind::Upload, upload: $file, filename: $file->getClientOriginalName());
    }

    /**
     * A file on the local filesystem.
     */
    public static function path(string $path, ?string $filename = null): self
    {
        if ($path === '') {
            throw new InvalidArgumentException('An ingest source needs a path.');
        }

        return new self(SourceKind::Path, path: $path, filename: $filename ?? basename($path));
    }

    /**
     * @param  resource  $stream
     */
    public static function stream($stream, string $filename): self
    {
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new InvalidArgumentException('An ingest source needs a readable stream.');
        }

        return new self(SourceKind::Stream, stream: $stream, filename: $filename);
    }

    /**
     * A file already on a Laravel disk: moved or copied without going through
     * PHP when the disk allows it. A file that the browser uploaded directly
     * to storage, through the application's own routes, is ingested this way.
     */
    public static function disk(string $disk, string $path, ?string $filename = null): self
    {
        if ($disk === '' || $path === '') {
            throw new InvalidArgumentException('An ingest source on a disk needs a disk and a path.');
        }
        if (in_array('..', explode('/', str_replace('\\', '/', $path)), true)) {
            throw new InvalidArgumentException(sprintf('The path "%s" leaves its disk.', $path));
        }

        return new self(SourceKind::Disk, path: ltrim($path, '/'), disk: $disk, filename: $filename ?? basename($path));
    }

    /**
     * A file to download, in a queued job, behind the SSRF guard.
     */
    public static function url(string $url, ?string $filename = null): self
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        if (! in_array(is_string($scheme) ? strtolower($scheme) : null, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            throw new InvalidArgumentException(sprintf('"%s" is not an http or https URL.', $url));
        }

        $path = parse_url($url, PHP_URL_PATH);

        return new self(SourceKind::Url, url: $url, filename: $filename ?? (is_string($path) && basename($path) !== '' ? basename($path) : null));
    }

    /**
     * A source from what applications pass to `ingest()`: an uploaded file,
     * a local path, a stream, or a source.
     */
    public static function from(mixed $source): self
    {
        return match (true) {
            $source instanceof self => $source,
            $source instanceof UploadedFile => self::upload($source),
            is_string($source) => self::path($source),
            is_resource($source) => self::stream($source, self::streamName($source)),
            default => throw new InvalidArgumentException(sprintf('Can\'t ingest a %s: pass an uploaded file, a path, a stream or a Source.', get_debug_type($source))),
        };
    }

    /**
     * @param  resource  $stream
     */
    private static function streamName($stream): string
    {
        $uri = stream_get_meta_data($stream)['uri'] ?? null;

        return is_string($uri) && $uri !== '' ? basename($uri) : 'stream';
    }
}
