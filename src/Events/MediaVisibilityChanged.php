<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Enums\Visibility;

/**
 * A media's visibility changed (Mediatheque::setVisibility()). Its stored
 * files follow in a queued job.
 */
final readonly class MediaVisibilityChanged
{
    public function __construct(
        public Media $media,
        public Visibility $previous,
        public Visibility $visibility,
    ) {}
}
