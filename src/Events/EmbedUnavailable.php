<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Transcoders\TranscoderError;

/**
 * A refresh found an embed gone from its provider: deleted, made private, or
 * no more embeddable (`provider_unavailable`). The media keeps its status and
 * its files, with `unavailable_at` in its metadata: hiding it, or telling its
 * owner, is the application's choice (docs/decisions/0031).
 */
final readonly class EmbedUnavailable
{
    public function __construct(
        public Media $media,
        public TranscoderError $error,
    ) {}
}
