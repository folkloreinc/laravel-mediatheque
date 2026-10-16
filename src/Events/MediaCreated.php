<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * A media was created by ingest. It is `pending` until it is probed.
 */
final readonly class MediaCreated
{
    public function __construct(
        public Media $media,
    ) {}
}
