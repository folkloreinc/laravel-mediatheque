<?php

namespace Folklore\Mediatheque\Exceptions;

use Folklore\Mediatheque\Contracts\Models\Media;
use RuntimeException;

/**
 * A media that models use can't be deleted unless the deletion is forced
 * (docs/decisions/0020). Nothing was deleted.
 */
class MediaInUse extends RuntimeException implements MediathequeException
{
    public function __construct(
        public readonly Media $media,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function for(Media $media): self
    {
        return new self($media, sprintf(
            'Media %s is in use: detach it from the models that use it, or force its deletion.',
            $media->uid(),
        ));
    }
}
