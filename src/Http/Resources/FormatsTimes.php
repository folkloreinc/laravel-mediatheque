<?php

namespace Folklore\Mediatheque\Http\Resources;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Times as the resources write them: ISO 8601 in UTC, and durations in
 * seconds.
 */
trait FormatsTimes
{
    private static function time(?CarbonInterface $time): ?string
    {
        return $time !== null ? CarbonImmutable::instance($time)->utc()->toIso8601ZuluString() : null;
    }

    /**
     * Seconds from one time to another; null when either is missing.
     */
    private static function seconds(?CarbonInterface $from, ?CarbonInterface $to): ?float
    {
        return $from !== null && $to !== null ? (float) $from->diffInSeconds($to) : null;
    }
}
