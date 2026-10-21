<?php

namespace Folklore\Mediatheque\Monitoring;

/**
 * How long a rendition takes to produce, and waits in the queue before it
 * starts, over the renditions that completed in a period. In seconds.
 */
final readonly class RenditionDurations
{
    public function __construct(
        public string $pipeline,
        public string $rendition,
        public string $transcoder,
        public int $count,
        public float $median,
        public float $p95,
        /** Null when none of them recorded when it was queued */
        public ?float $waitMedian,
        public ?float $waitP95,
    ) {}
}
