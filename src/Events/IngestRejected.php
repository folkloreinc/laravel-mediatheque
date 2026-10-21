<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Enums\RejectionReason;

/**
 * Ingest refused a file: nothing was stored, and no media exists to record
 * why (docs/decisions/0017). Dispatched before `FileRejected` is thrown.
 */
final readonly class IngestRejected
{
    public function __construct(
        public RejectionReason $reason,
        public string $message,
        /** The name the file was given, if any. */
        public ?string $filename,
    ) {}
}
