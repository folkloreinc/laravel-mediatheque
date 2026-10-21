<?php

namespace Folklore\Mediatheque\Exceptions;

use RuntimeException;

/**
 * The upload doesn't exist for this user: unknown, another user's, aborted,
 * already completed, or not the multipart upload its key started
 * (docs/decisions/0015, 0016). The routes answer `404`.
 */
class UploadNotFound extends RuntimeException implements MediathequeException
{
    public static function make(): self
    {
        return new self('The upload doesn\'t exist.');
    }
}
