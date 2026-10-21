<?php

namespace Folklore\Mediatheque\Exceptions;

use InvalidArgumentException;

/**
 * A request to sign that is no S3 request: no operation has this method,
 * upload id and part number (docs/decisions/0016). The routes answer `422`
 * (`reason: unknown_operation`).
 */
class UnknownS3Operation extends InvalidArgumentException implements MediathequeException
{
    public const string REASON = 'unknown_operation';

    public static function make(): self
    {
        return new self('No S3 request has this method, upload id and part number.');
    }
}
