<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * A media was deleted (soft deleted, unless forced).
 */
final readonly class MediaDeleted
{
    public function __construct(
        public Media $media,
    ) {}
}
