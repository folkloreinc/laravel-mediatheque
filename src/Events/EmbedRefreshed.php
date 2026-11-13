<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;

/**
 * An embed was probed again, and its provider still has it: its metadata
 * holds what the provider says now (docs/decisions/0031).
 */
final readonly class EmbedRefreshed
{
    public function __construct(
        public Media $media,
        /** @var array<string, mixed> Canonical keys */
        public array $metadata,
    ) {}
}
