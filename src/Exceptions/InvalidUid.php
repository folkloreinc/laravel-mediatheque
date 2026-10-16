<?php

namespace Folklore\Mediatheque\Exceptions;

use InvalidArgumentException;

class InvalidUid extends InvalidArgumentException implements MediathequeException
{
    public static function prefix(string $prefix): self
    {
        return new self(sprintf(
            'The uid prefix "%s" is invalid: it must be lowercase letters and digits, starting with a letter (med, upl).',
            $prefix,
        ));
    }
}
