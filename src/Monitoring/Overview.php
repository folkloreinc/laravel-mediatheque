<?php

namespace Folklore\Mediatheque\Monitoring;

use Carbon\CarbonImmutable;

/**
 * What the package is doing now, and what ended in a period.
 */
final readonly class Overview
{
    /**
     * @param  array<string, int>  $media  Media that aren't deleted, by status
     * @param  array<string, int>  $runsEnded  Runs that ended in the period, by status
     * @param  array<string, int>  $uploadsEnded  Uploads that ended in the period, by status
     */
    public function __construct(
        public Period $period,
        public array $media,
        public int $runsInProgress,
        public int $stuckRuns,
        public array $runsEnded,
        /** When the rendition that has waited the longest in the queue was queued */
        public ?CarbonImmutable $oldestQueuedAt,
        public int $uploadsOpen,
        public array $uploadsEnded,
    ) {}
}
