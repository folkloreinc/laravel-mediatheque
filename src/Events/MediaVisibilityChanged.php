<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\Visibility;

/**
 * A media's visibility changed. Its files are updated in a queued job.
 */
final readonly class MediaVisibilityChanged
{
    public function __construct(
        public Media $media,
        public Visibility $previous,
    ) {}
}
