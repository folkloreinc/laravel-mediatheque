<?php

namespace Folklore\Mediatheque\Exceptions;

use Folklore\Mediatheque\Enums\RejectionReason;
use RuntimeException;

/**
 * Ingest refused a file. Nothing was stored.
 */
class FileRejected extends RuntimeException implements MediathequeException
{
    public function __construct(
        public readonly RejectionReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(RejectionReason $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
