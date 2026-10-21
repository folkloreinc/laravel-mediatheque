<?php

namespace Folklore\Mediatheque\Exceptions;

use RuntimeException;

/**
 * The upload is still open but expired (`uploads.expires_after`): it can't be
 * continued (docs/decisions/0015). The routes answer `410`.
 */
class UploadExpired extends RuntimeException implements MediathequeException
{
    public static function make(): self
    {
        return new self('The upload expired.');
    }
}
