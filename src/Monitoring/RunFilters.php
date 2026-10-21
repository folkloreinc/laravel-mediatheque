<?php

namespace Folklore\Mediatheque\Monitoring;

use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;

/**
 * Which runs Monitoring::runs() lists. Every filter that is set applies.
 */
final readonly class RunFilters
{
    public function __construct(
        public ?PipelineStatus $status = null,
        /** The pipeline's name, `probe` included */
        public ?string $pipeline = null,
        public ?string $transcoder = null,
        /** The media's type */
        public ?string $mediaType = null,
        /** The run's error code */
        public ?TranscoderErrorCode $errorCode = null,
        /** The media's uid */
        public ?string $media = null,
        /** Runs created in this period */
        public ?Period $period = null,
        /** Only runs that haven't ended after `monitoring.stuck_after` */
        public bool $stuck = false,
    ) {}
}
