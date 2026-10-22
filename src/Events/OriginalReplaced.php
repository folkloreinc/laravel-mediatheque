<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * A media's original was replaced (docs/decisions/0019). The media is
 * `pending`: it is probed again, then its renditions are produced again.
 *
 * Checksums are SHA-256 in hex. A checksum is null for an original copied
 * from a disk until its probe computes it (docs/decisions/0013).
 */
final readonly class OriginalReplaced
{
    public function __construct(
        public Media $media,
        public ?string $previousChecksum,
        public ?string $checksum,
    ) {}
}
