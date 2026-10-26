<?php

namespace Folklore\Mediatheque\Testing;

use DateTimeInterface;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Urls\Download;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Support\Facades\Date;

/**
 * URLs on the fake's disks. Temporary URLs carry their expiry and a fake
 * signature, so tests can tell them apart, and the name a download URL saves
 * its file under (`download`).
 */
class FakeUrlGenerator implements UrlGenerator
{
    public function __construct(
        private readonly Filesystems $filesystems,
        private readonly int $temporaryTtl,
    ) {}

    public function url(File $file): ?string
    {
        if ($file->visibility() === Visibility::Private) {
            return $this->temporaryUrl($file, Date::now()->addSeconds($this->temporaryTtl));
        }

        return $this->publicUrl($file);
    }

    public function temporaryUrl(File $file, DateTimeInterface $expiration, bool|string $download = false): ?string
    {
        $download = Download::of($file, $download);
        $url = $this->publicUrl($file);
        if ($url === null) {
            return null;
        }

        $expires = $expiration->getTimestamp();

        return $url.'?'.http_build_query([
            'expires' => $expires,
            'download' => $download?->filename,
            'signature' => hash_hmac('sha256', implode('|', array_filter([$file->path(), $expires, $download?->filename], fn ($part) => $part !== null)), 'mediatheque-fake'),
        ]);
    }

    private function publicUrl(File $file): ?string
    {
        $source = $file->source();
        $path = $file->path();

        if ($file->isRemote() || $source === null || $path === null) {
            return null;
        }

        $disk = $this->filesystems->disk($source);

        return method_exists($disk, 'url') ? (string) $disk->url($path) : '/'.$source.'/'.$path;
    }
}
