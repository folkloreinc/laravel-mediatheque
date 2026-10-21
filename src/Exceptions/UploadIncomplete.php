<?php

namespace Folklore\Mediatheque\Exceptions;

use RuntimeException;

/**
 * The upload can't be completed or ingested yet (docs/decisions/0016): its
 * file isn't on the storage (`incomplete`), or it has no multipart upload to
 * complete with parts (`not_multipart`). The routes answer `409`.
 */
class UploadIncomplete extends RuntimeException implements MediathequeException
{
    public const string INCOMPLETE = 'incomplete';

    public const string NOT_MULTIPART = 'not_multipart';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function notOnStorage(): self
    {
        return new self(self::INCOMPLETE, 'The storage has no file for this upload: complete it on the storage first, or send its parts.');
    }

    public static function notMultipart(): self
    {
        return new self(self::NOT_MULTIPART, 'The upload has no multipart upload: complete it on the storage, then complete it without parts.');
    }
}
