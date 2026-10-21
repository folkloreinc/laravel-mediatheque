<?php

namespace Folklore\Mediatheque\Monitoring;

use Carbon\CarbonImmutable;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;

/**
 * Renditions that failed in a period with the same error code, in the same
 * pipeline, transcoder and rendition.
 */
final readonly class FailureGroup
{
    public function __construct(
        public TranscoderErrorCode $code,
        public string $pipeline,
        public string $transcoder,
        public string $rendition,
        public int $count,
        /** The message of the last failure */
        public string $lastMessage,
        public CarbonImmutable $lastFailedAt,
    ) {}
}
