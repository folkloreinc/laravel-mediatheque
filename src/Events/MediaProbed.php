<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * A media's probe results were stored. Its pipeline starts on this event, never before.
 */
final readonly class MediaProbed
{
    public function __construct(
        public Media $media,
        /** @var array<string, mixed> Canonical keys */
        public array $metadata,
    ) {}
}
