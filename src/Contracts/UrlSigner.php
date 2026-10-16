<?php

namespace Folklore\Mediatheque\Contracts;

use DateTimeInterface;

/**
 * Signs URLs for CDNs that sign their own (docs/decisions/0007). The
 * UrlGenerator calls it for the temporary URLs of private files, on sources
 * that set a signer, instead of the disk's own signing.
 */
interface UrlSigner
{
    /**
     * A URL that stops working after the expiration.
     */
    public function sign(string $url, DateTimeInterface $expiration): string;
}
