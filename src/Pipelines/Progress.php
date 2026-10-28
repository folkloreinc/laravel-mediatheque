<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Metadata\MetadataKey;

/**
 * The progress of a run, from its renditions (docs/decisions/0023).
 *
 * A rendition that transcodes the whole input weighs the input's duration
 * in seconds; any other rendition (poster, thumbnails, waveform, probe)
 * weighs one second. An ended rendition is done. A rendition that nothing
 * measured yet counts as not started, so a short rendition that ends first
 * doesn't make the run look done, and the run's progress never goes down.
 */
final class Progress
{
    /**
     * The operations that read and encode the whole input.
     */
    public const array TRANSCODES = ['video.transcode', 'video.hls', 'video.reframe', 'audio.transcode'];

    /**
     * Between 0 and 1, or null while none of its renditions was measured
     * or ended.
     */
    public static function ofRun(PipelineRun $run): ?float
    {
        $duration = self::duration($run->media()->metadata(MetadataKey::Duration->value));
        $total = 0.0;
        $done = 0.0;
        $measured = false;

        foreach ($run->renditions() as $name => $rendition) {
            $job = $run->job($name);
            if ($job === null) {
                continue;
            }

            $weight = in_array($rendition->operation, self::TRANSCODES, true) && $duration !== null ? max(1.0, $duration) : 1.0;
            $progress = $job->status()->isFinal() ? 1.0 : $job->progress();
            $total += $weight;

            if ($progress !== null) {
                $done += $weight * $progress;
                $measured = true;
            }
        }

        if ($total === 0.0) {
            return $run->status()->isFinal() ? 1.0 : null;
        }

        return $measured ? min(1.0, $done / $total) : null;
    }

    /**
     * Whether a progress that went from `$before` to `$after` passed a
     * multiple of the step: when the recorder dispatches RenditionProgressed.
     */
    public static function passesStep(?float $before, float $after, float $step): bool
    {
        // The epsilon keeps 0.15 / 0.05 from flooring to 2
        return floor($after / $step + 1e-9) > floor(($before ?? 0.0) / $step + 1e-9);
    }

    private static function duration(mixed $duration): ?float
    {
        return (is_int($duration) || is_float($duration)) && $duration > 0 ? (float) $duration : null;
    }
}
