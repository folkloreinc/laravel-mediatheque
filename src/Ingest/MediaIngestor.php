<?php

namespace Folklore\Mediatheque\Ingest;

use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Models\File as FileContract;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\RejectionReason;
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
 * 2. The type's maximum size; SVG files are sanitized.
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
        private readonly LocalFiles $files,
        private readonly Filesystems $filesystems,
        private readonly ConnectionResolverInterface $database,
        private readonly Events $events,
        private readonly Jobs $jobs,
    ) {}

    public function ingest(Source $source, IngestOptions $options = new IngestOptions): Media
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

            $sanitized = null;
            if (ContentType::isSvg($mime)) {
                $sanitized = $this->sanitizeSvg($source, $local, $temporary);
                $local = $sanitized;
                $size = (int) filesize($sanitized);
            }

            return $this->store($source, $options, $type, $mime, $size, $local, $sanitized !== null);
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

    private function store(Source $source, IngestOptions $options, TypeDefinition $type, string $mime, int $size, string $local, bool $sanitized): Media
    {
        $visibility = $options->visibility ?? $this->config->defaultVisibility();
        $pipeline = $options->pipeline !== null ? $this->config->pipeline($options->pipeline)->name : null;
        $disk = $options->source !== null ? $this->config->disk('source', $options->source) : $this->config->defaultSource();
        $extension = ContentType::extension($mime);
        $readsLocally = $source->kind !== SourceKind::Disk || $sanitized;
        $checksum = $readsLocally ? (string) hash_file('sha256', $local) : null;

        $media = Models::media()::query()->getModel()->newInstance();
        $media->forceFill([
            ...$options->attributes,
            'uid' => Uid::generate($this->config->mediaPrefix()),
            'type' => $type->name,
            'name' => $options->name ?? $source->filename,
            'status' => MediaStatus::Pending,
            'visibility' => $visibility,
            'data' => $options->data === [] ? null : $options->data,
            'metadata' => array_filter([
                MetadataKey::Filename->value => $source->filename,
                MetadataKey::Mime->value => $mime,
                MetadataKey::Extension->value => $extension,
                MetadataKey::Size->value => $size,
                MetadataKey::Checksum->value => $checksum,
            ], fn (mixed $value) => $value !== null),
        ]);

        $path = $this->paths->original($media, $extension);
        $filesystem = $this->filesystems->disk($disk);
        $put = ['visibility' => $visibility->value, 'ContentType' => $mime];

        if ($readsLocally) {
            $stream = fopen($local, 'rb') ?: throw FileRejected::because(RejectionReason::Unreadable, 'The file can\'t be read.');
            try {
                $filesystem->writeStream($path, $stream, $put);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        } else {
            $this->copyFromDisk((string) $source->disk, (string) $source->path, $disk, $path, $put);
        }

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
