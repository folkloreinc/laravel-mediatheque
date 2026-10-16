<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * Every rendition of the media's pipeline ended, and its main rendition completed.
 */
final readonly class MediaReady
{
    public function __construct(
        public Media $media,
    ) {}
}
