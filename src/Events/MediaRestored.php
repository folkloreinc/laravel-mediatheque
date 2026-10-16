<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * A soft-deleted media was restored.
 */
final readonly class MediaRestored
{
    public function __construct(
        public Media $media,
    ) {}
}
