<?php

namespace Folklore\Mediatheque\Urls;

use DateTimeInterface;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Date;

/**
 * URLs of files on Laravel disks (docs/decisions/0007).
 *
 * - A public file gets its disk's URL.
 * - A private file only gets temporary URLs, signed by its disk: S3 and
 *   S3-compatible disks sign them, and local disks that serve their files
 *   (`'serve' => true`) sign a route that streams them. On a disk that can't
 *   sign URLs, a private file has no URL.
 */
class DiskUrlGenerator implements UrlGenerator
{
    public function __construct(
        private readonly Filesystems $filesystems,
        private readonly MediathequeConfig $config,
    ) {}

    public function url(File $file): ?string
    {
        if ($file->visibility() === Visibility::Private) {
            return $this->temporaryUrl($file, Date::now()->addSeconds($this->config->temporaryTtl()));
        }

        $disk = $this->disk($file);
        $path = $file->path();

        return $disk !== null && $path !== null ? $disk->url($path) : null;
    }

    public function temporaryUrl(File $file, DateTimeInterface $expiration): ?string
    {
        $disk = $this->disk($file);
        $path = $file->path();

        if ($disk === null || $path === null || ! $disk->providesTemporaryUrls()) {
            return null;
        }

        return $disk->temporaryUrl($path, $expiration);
    }

    private function disk(File $file): ?FilesystemAdapter
    {
        $source = $file->source();

        if ($file->isRemote() || $source === null) {
            return null;
        }

        $disk = $this->filesystems->disk($source);

        return $disk instanceof FilesystemAdapter ? $disk : null;
    }
}
