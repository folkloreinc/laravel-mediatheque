<?php

namespace Folklore\Mediatheque\Exceptions;

use InvalidArgumentException;

class InvalidReference extends InvalidArgumentException implements MediathequeException
{
    public static function for(string $value): self
    {
        return new self(sprintf(
            '"%s" is not a media reference: expected media://{uid} or medias://{id}.',
            mb_strimwidth($value, 0, 100, '…'),
        ));
    }
}
