<?php

namespace Folklore\Mediatheque\Contracts;

use DateTimeInterface;
use Folklore\Mediatheque\Contracts\Models\File;

/**
 * The URLs of files (docs/decisions/0007).
 *
 * - A public file gets its source's URL, through its CDN prefix when one is
 *   configured.
 * - A private file only gets temporary URLs: url() returns one with the
 *   configured lifetime (`urls.temporary_ttl`).
 * - A file with no URL (no source, a provider that serves none) gets null.
 * - A temporary URL can download the file rather than show it, under a file
 *   name (#102), where its source supports it: S3 and S3-compatible disks
 *   do. Elsewhere, the option is ignored and the URL is the same as without
 *   it.
 */
interface UrlGenerator
{
    public function url(File $file): ?string;

    /**
     * @param  bool|string  $download  `true` to download the file under its
     *                                 name, a file name to download it under that name, or `false` to
     *                                 show it
     *
     * @throws \InvalidArgumentException when the name to download under is not a file name
     */
    public function temporaryUrl(File $file, DateTimeInterface $expiration, bool|string $download = false): ?string;
}
