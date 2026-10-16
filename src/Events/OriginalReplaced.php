<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * A media's original was replaced. Its renditions are produced again.
 */
final readonly class OriginalReplaced
{
    public function __construct(
        public Media $media,
        public File $previous,
    ) {}
}
