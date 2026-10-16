<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * A media was saved with changes.
 */
final readonly class MediaUpdated
{
    public function __construct(
        public Media $media,
    ) {}
}
