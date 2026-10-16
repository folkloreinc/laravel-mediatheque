<?php

namespace Folklore\Mediatheque\Testing;

use DateTimeInterface;
use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\UrlGenerator;
use Folklore\Mediatheque\Enums\Visibility;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Support\Facades\Date;

/**
 * URLs on the fake's disks. Temporary URLs carry their expiry and a fake
 * signature, so tests can tell them apart.
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

    public function temporaryUrl(File $file, DateTimeInterface $expiration): ?string
    {
        $url = $this->publicUrl($file);
        if ($url === null) {
            return null;
        }

        $expires = $expiration->getTimestamp();

        return $url.'?'.http_build_query([
            'expires' => $expires,
            'signature' => hash_hmac('sha256', $file->path().'|'.$expires, 'mediatheque-fake'),
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
