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
 */
interface UrlGenerator
{
    public function url(File $file): ?string;

    public function temporaryUrl(File $file, DateTimeInterface $expiration): ?string;
}
