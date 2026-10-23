<?php

namespace Folklore\Mediatheque\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * A model that uses a media: one row of the `medias_pivot` table
 * (docs/decisions/0020). A model that uses a media under two handles has a
 * usage for each.
 */
final readonly class MediaUsage
{
    public function __construct(
        /** The model's morph class, as the pivot stores it */
        public string $type,
        public int|string $id,
        /** The handle the model attached the media under; null on rows that 1.x wrote */
        public ?string $handle,
        public int $order,
        /** The model, loaded without its global scopes; null when it no longer exists */
        public ?Model $model = null,
    ) {}
}
