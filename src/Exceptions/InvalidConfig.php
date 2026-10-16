<?php

namespace Folklore\Mediatheque\Exceptions;

use InvalidArgumentException;

/**
 * A value of `config('mediatheque')` that the package can't use. Thrown when
 * the config is read, with the key that is wrong.
 */
class InvalidConfig extends InvalidArgumentException implements MediathequeException
{
    public static function at(string $key, string $expected): self
    {
        return new self(sprintf('The config value mediatheque.%s is invalid: %s.', $key, $expected));
    }
}
