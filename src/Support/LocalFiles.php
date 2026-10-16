<?php

namespace Folklore\Mediatheque\Support;

use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * Local copies of files on disks, for the tools that read local paths, and
 * temporary files. Every copy and temporary file lives in the package's
 * temporary directory and is removed by its owner, also when a job fails.
 */
final class LocalFiles
{
    public function __construct(
        private readonly Filesystems $filesystems,
    ) {}

    /**
     * A local path of a file on a disk: the file itself on a local disk, a
     * temporary copy otherwise.
     */
    public function copy(string $disk, string $path): LocalCopy
    {
        $filesystem = $this->filesystems->disk($disk);

        if ($filesystem instanceof FilesystemAdapter && $filesystem->getAdapter() instanceof LocalFilesystemAdapter) {
            $local = $filesystem->path($path);
            if (! is_file($local)) {
                throw new RuntimeException(sprintf('The file "%s" is missing from the disk "%s".', $path, $disk));
            }

            return new LocalCopy($local, false);
        }

        $stream = $filesystem->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException(sprintf('The file "%s" can\'t be read from the disk "%s".', $path, $disk));
        }

        $local = $this->temporaryPath(pathinfo($path, PATHINFO_EXTENSION));
        $target = fopen($local, 'wb');
        if ($target === false) {
            fclose($stream);
            throw new RuntimeException(sprintf('Can\'t write the temporary file "%s".', $local));
        }

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($stream);
            fclose($target);
        }

        return new LocalCopy($local, true);
    }

    /**
     * The SHA-256 of a file on a disk, read as a stream.
     */
    public function checksum(string $disk, string $path): string
    {
        $stream = $this->filesystems->disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new RuntimeException(sprintf('The file "%s" can\'t be read from the disk "%s".', $path, $disk));
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }

    /**
     * A new path in the package's temporary directory. Nothing is created.
     */
    public function temporaryPath(string $extension = ''): string
    {
        return self::root().'/'.bin2hex(random_bytes(16)).($extension !== '' ? '.'.$extension : '');
    }

    /**
     * A new empty directory in the package's temporary directory.
     */
    public function temporaryDirectory(): string
    {
        $directory = self::root().'/'.bin2hex(random_bytes(16));

        if (! mkdir($directory, 0700) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Can\'t create the temporary directory "%s".', $directory));
        }

        return $directory;
    }

    /**
     * Remove a temporary directory and what it holds.
     */
    public static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    public static function root(): string
    {
        $root = rtrim(sys_get_temp_dir(), '/').'/mediatheque';

        if (! is_dir($root) && ! @mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException(sprintf('Can\'t create the temporary directory "%s".', $root));
        }

        return $root;
    }
}
