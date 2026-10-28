<?php

namespace Folklore\Mediatheque\Ingest;

use Closure;
use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Events\IngestRejected;
use Folklore\Mediatheque\Events\MediaCreated;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Jobs\ProbeMedia;
use Folklore\Mediatheque\Metadata\MetadataKey;
use Folklore\Mediatheque\Support\LocalFiles;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\TypeDefinition;
use Folklore\Mediatheque\Support\Uid;
use Illuminate\Contracts\Bus\Dispatcher as Jobs;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Throwable;

/**
 * The ingest step every way in ends in (docs/decisions/0006).
 *
 * 1. The type comes from the mime sniffed from the content, never from the
 *    client's file name, extension or `Content-Type`. Files of mimes that a
 *    server could execute or a browser render as an active document are
 *    never stored, whatever the types allow.
 * 2. The type's maximum size; SVG files are sanitized, and the location is
 *    removed from images (GpsStripper), without re-encoding them.
 * 3. The media is created in memory with its uid and the application's
 *    attributes, and the path formatter builds the original's path, with an
 *    extension derived from the detected mime.
 * 4. The original is stored, then the media and its original are saved in a
 *    transaction. If saving fails, the stored file is deleted: a rejected or
 *    failed ingest leaves neither a file nor a row.
 * 5. After the commit: `MediaCreated`, and the probe job.
 */
class MediaIngestor implements Ingestor
{
    /**
     * Bytes read from a file on a disk to sniff its mime, when the whole file
     * is not needed.
     */
    private const int SNIFF_BYTES = 1024 * 1024;

    public function __construct(
        private readonly MediathequeConfig $config,
        private readonly PathFormatter $paths,
        private readonly SvgSanitizer $svg,
        private readonly GpsStripper $gps,
        private readonly LocalFiles $files,
        private readonly Filesystems $filesystems,
        private readonly ConnectionResolverInterface $database,
        private readonly Events $events,
        private readonly Jobs $jobs,
    ) {}

    public function ingest(Source $source, IngestOptions $options = new IngestOptions): Media
    {
        return $this->accept($source, fn (IncomingFile $file) => $this->store($file, $options));
    }

    /**
     * Read and validate a file as ingest does, and hand it to `$then`, which
     * stores it. A rejected file dispatches `IngestRejected` and throws
     * FileRejected, also when `$then` rejects it. Temporary files are
     * removed once `$then` returns.
     *
     * @template TResult
     *
     * @param  Closure(IncomingFile): TResult  $then
     * @return TResult
     *
     * @throws FileRejected
     */
    public function accept(Source $source, Closure $then): mixed
    {
        if ($source->kind === SourceKind::Url) {
            throw new InvalidArgumentException('Ingest from a URL comes with the SSRF guard, in a later alpha. Download the file, then ingest it.');
        }

        $temporary = [];

        try {
            [$local, $mime, $size] = $this->inspect($source, $temporary);

            $type = $this->config->detectType($mime);
            if ($type === null || ContentType::isBlocked($mime)) {
                throw FileRejected::because(
                    $type === null ? RejectionReason::UnknownType : RejectionReason::MimeNotAllowed,
                    sprintf('The file "%s" (%s) matches no media type that accepts it.', $source->filename ?? 'file', $mime),
                );
            }

            $this->checkSize($source, $type, $size);

            $sanitized = false;
            if (ContentType::isSvg($mime)) {
                $local = $this->sanitizeSvg($source, $local, $temporary);
                $size = (int) filesize($local);
                $sanitized = true;
            }

            if ($this->config->stripsLocation() && GpsStripper::supports($mime)) {
                $stripped = $this->stripGps($source, $local, $mime, $temporary);
                if ($stripped !== null) {
                    $local = $stripped;
                    $sanitized = true;
                }
            }

            return $then(new IncomingFile($source, $type, $mime, $size, $local, $sanitized));
        } catch (FileRejected $exception) {
            $this->events->dispatch(new IngestRejected($exception->reason, $exception->getMessage(), $source->filename));

            throw $exception;
        } finally {
            foreach ($temporary as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Store an accepted file at a path, with a visibility: from its local
     * file, or copied from its disk.
     */
    public function write(IncomingFile $file, string $disk, string $path, Visibility $visibility): void
    {
        $put = ['visibility' => $visibility->value, 'ContentType' => $file->mime];

        if (! $file->readsLocally()) {
            $this->copyFromDisk((string) $file->source->disk, (string) $file->source->path, $disk, $path, $put);

            return;
        }

        $stream = fopen($file->local, 'rb') ?: throw FileRejected::because(RejectionReason::Unreadable, 'The file can\'t be read.');
        try {
            $this->filesystems->disk($disk)->writeStream($path, $stream, $put);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * A local file to sniff, the mime and the size. For a file on a disk, the
     * local file holds its first bytes only.
     *
     * @param  list<string>  $temporary
     * @return array{string, string, int}
     */
    private function inspect(Source $source, array &$temporary): array
    {
        $unreadable = fn () => FileRejected::because(RejectionReason::Unreadable, sprintf('The file "%s" can\'t be read.', $source->filename ?? 'file'));

        switch ($source->kind) {
            case SourceKind::Upload:
                $upload = $source->upload;
                $path = $upload instanceof UploadedFile && $upload->isValid() ? $upload->getRealPath() : false;
                if ($path === false || ! is_file($path)) {
                    throw $unreadable();
                }

                return [$path, ContentType::sniff($path), (int) filesize($path)];

            case SourceKind::Path:
                $path = (string) $source->path;
                if (! is_file($path) || ! is_readable($path)) {
                    throw $unreadable();
                }

                return [$path, ContentType::sniff($path), (int) filesize($path)];

            case SourceKind::Stream:
                $stream = $source->stream;
                if (! is_resource($stream)) {
                    throw $unreadable();
                }

                $path = $this->files->temporaryPath();
                $temporary[] = $path;
                $target = fopen($path, 'wb');
                if ($target === false) {
                    throw $unreadable();
                }
                stream_copy_to_stream($stream, $target);
                fclose($target);

                return [$path, ContentType::sniff($path), (int) filesize($path)];

            case SourceKind::Disk:
                $disk = $this->filesystems->disk((string) $source->disk);
                $file = (string) $source->path;
                if (! $disk->exists($file)) {
                    throw $unreadable();
                }

                $path = $this->files->temporaryPath();
                $temporary[] = $path;
                $this->copyHead($disk, $file, $path) ?: throw $unreadable();

                return [$path, ContentType::sniff($path), $disk->size($file)];

            case SourceKind::Url:
                throw $unreadable();
        }
    }

    /**
     * Copy the first bytes of a file on a disk, enough to sniff its mime.
     */
    private function copyHead(Filesystem $disk, string $file, string $target): bool
    {
        $stream = $disk->readStream($file);
        $output = fopen($target, 'wb');

        if (! is_resource($stream) || $output === false) {
            return false;
        }

        try {
            return stream_copy_to_stream($stream, $output, self::SNIFF_BYTES) !== false;
        } finally {
            fclose($stream);
            fclose($output);
        }
    }

    private function checkSize(Source $source, TypeDefinition $type, int $size): void
    {
        $max = $this->config->maxSize($type);

        if ($max !== null && $size > $max) {
            throw FileRejected::because(RejectionReason::TooLarge, sprintf(
                'The file "%s" is %d bytes; a %s can\'t be larger than %d bytes.',
                $source->filename ?? 'file',
                $size,
                $type->name,
                $max,
            ));
        }
    }

    /**
     * The path of a sanitized copy of an SVG.
     *
     * @param  list<string>  $temporary
     */
    private function sanitizeSvg(Source $source, string $local, array &$temporary): string
    {
        $contents = $source->kind === SourceKind::Disk
            ? (string) $this->filesystems->disk((string) $source->disk)->get((string) $source->path)
            : (string) file_get_contents($local);

        $sanitized = $this->svg->sanitize($contents) ?? throw FileRejected::because(
            RejectionReason::InvalidSvg,
            sprintf('The file "%s" is not a valid SVG document.', $source->filename ?? 'file'),
        );

        $path = $this->files->temporaryPath('svg');
        $temporary[] = $path;
        file_put_contents($path, $sanitized);

        return $path;
    }

    /**
     * The path of a copy of an image without its location, or null when it
     * has none. An image on a disk is downloaded to be read whole; it is
     * still copied from its disk when it has no location.
     *
     * @param  list<string>  $temporary
     */
    private function stripGps(Source $source, string $local, string $mime, array &$temporary): ?string
    {
        $path = $this->files->temporaryPath();
        $temporary[] = $path;

        if ($source->kind === SourceKind::Disk) {
            $stream = $this->filesystems->disk((string) $source->disk)->readStream((string) $source->path);
            $output = fopen($path, 'wb');
            if (! is_resource($stream) || $output === false) {
                throw FileRejected::because(RejectionReason::Unreadable, sprintf('The file "%s" can\'t be read.', $source->filename ?? 'file'));
            }
            stream_copy_to_stream($stream, $output);
            fclose($stream);
            fclose($output);
        } elseif (! copy($local, $path)) {
            throw FileRejected::because(RejectionReason::Unreadable, sprintf('The file "%s" can\'t be read.', $source->filename ?? 'file'));
        }

        $patches = $this->gps->patches($path, $mime);
        if ($patches === []) {
            return null;
        }

        GpsStripper::apply($path, $patches);

        return $path;
    }

    private function store(IncomingFile $file, IngestOptions $options): Media
    {
        $source = $file->source;
        $visibility = $options->visibility ?? $this->config->defaultVisibility();
        $pipeline = $options->pipeline !== null ? $this->config->pipeline($options->pipeline)->name : null;
        $disk = $options->source !== null ? $this->config->disk('source', $options->source) : $this->config->defaultSource();
        $mime = $file->mime;
        $size = $file->size;
        $extension = $file->extension();
        $checksum = $file->checksum();

        $media = Models::media()::query()->getModel()->newInstance();
        $media->forceFill([
            ...$options->attributes,
            'uid' => Uid::generate($this->config->mediaPrefix()),
            'type' => $file->type->name,
            'name' => $options->name ?? $source->filename,
            'status' => MediaStatus::Pending,
            'visibility' => $visibility,
            'data' => $options->data === [] ? null : $options->data,
            'metadata' => self::metadata($file, $checksum),
        ]);

        $path = $this->paths->original($media, $extension);
        $filesystem = $this->filesystems->disk($disk);
        $this->write($file, $disk, $path, $visibility);

        try {
            $this->database->connection($media->getConnectionName())->transaction(function () use ($media, $source, $disk, $path, $mime, $size, $checksum) {
                $media->save();

                $original = Models::file()::query()->getModel()->newInstance();
                $original->forceFill([
                    'media_id' => $media->getKey(),
                    'handle' => FileContract::ORIGINAL,
                    'role' => FileContract::ORIGINAL,
                    'source' => $disk,
                    'path' => $path,
                    'name' => $source->filename,
                    'mime' => $mime,
                    'size' => $size,
                    'checksum' => $checksum,
                ])->save();

                $media->setRelation('mediaFiles', $media->newCollection([$original]));
                $original->setRelation('media', $media);
            });
        } catch (Throwable $exception) {
            $filesystem->delete($path);

            throw $exception;
        }

        $this->afterCommit($media, $pipeline);

        return $media;
    }

    /**
     * The metadata of a media from its original, until it is probed.
     *
     * @return array<string, mixed>
     */
    public static function metadata(IncomingFile $file, ?string $checksum): array
    {
        return array_filter([
            MetadataKey::Filename->value => $file->source->filename,
            MetadataKey::Mime->value => $file->mime,
            MetadataKey::Extension->value => $file->extension(),
            MetadataKey::Size->value => $file->size,
            MetadataKey::Checksum->value => $checksum,
        ], fn (mixed $value) => $value !== null);
    }

    /**
     * Copy a file from a disk: on the server when it is the same disk (S3
     * copies objects without downloading them), as a stream otherwise.
     *
     * @param  array<string, string>  $options
     */
    private function copyFromDisk(string $fromDisk, string $from, string $toDisk, string $to, array $options): void
    {
        $target = $this->filesystems->disk($toDisk);

        if ($fromDisk === $toDisk && $target instanceof FilesystemAdapter) {
            // One request on S3: the copy gets the media's visibility, without reading the source's, and
            // the detected type rather than the one the client stored with the file (docs/decisions/0006)
            $target->getDriver()->copy($from, $to, [
                'retain_visibility' => false,
                'visibility' => $options['visibility'],
                'ContentType' => $options['ContentType'],
                'MetadataDirective' => 'REPLACE',
            ]);

            return;
        }

        if ($fromDisk === $toDisk) {
            $target->copy($from, $to);
            $target->setVisibility($to, $options['visibility']);

            return;
        }

        $stream = $this->filesystems->disk($fromDisk)->readStream($from);
        if (! is_resource($stream)) {
            throw FileRejected::because(RejectionReason::Unreadable, sprintf('The file "%s" can\'t be read.', $from));
        }

        try {
            $target->writeStream($to, $stream, $options);
        } finally {
            fclose($stream);
        }
    }

    /**
     * `MediaCreated` and the probe, once the media is committed: when the
     * application ingests inside its own transaction, after it commits.
     */
    private function afterCommit(Model&Media $media, ?string $pipeline): void
    {
        $created = function () use ($media, $pipeline) {
            $this->events->dispatch(new MediaCreated($media));
            $key = $media->getKey();
            if (is_int($key) || is_string($key)) {
                $this->jobs->dispatch(new ProbeMedia($key, $pipeline));
            }
        };

        $connection = $this->database->connection($media->getConnectionName());
        $connection instanceof Connection ? $connection->afterCommit($created) : $created();
    }
}
