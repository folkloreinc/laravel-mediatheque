<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Transcoders\TranscoderError;

/**
 * A media's probe failed, or its main rendition didn't complete.
 */
final readonly class MediaFailed
{
    public function __construct(
        public Media $media,
        public ?TranscoderError $error,
    ) {}
}
