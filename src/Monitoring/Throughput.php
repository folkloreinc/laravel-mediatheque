<?php

namespace Folklore\Mediatheque\Monitoring;

use Carbon\CarbonImmutable;

/**
 * Runs that ended in a period, hour by hour, and the bytes ingested and
 * produced in it.
 */
final readonly class Throughput
{
    /**
     * @param  array<string, array<string, int>>  $runsPerHour  Keyed by the hour's start (ISO 8601), then by status; hours without runs are left out
     */
    public function __construct(
        public Period $period,
        public array $runsPerHour,
        /** Sizes of the originals of media created in the period */
        public int $bytesIngested,
        /** Sizes of the files renditions produced in the period */
        public int $bytesProduced,
    ) {}

    /**
     * The start of the hour of a time, as runsPerHour keys it.
     */
    public static function hour(CarbonImmutable $time): string
    {
        return $time->utc()->startOfHour()->toIso8601ZuluString();
    }
}
