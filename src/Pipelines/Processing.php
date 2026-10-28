<?php

namespace Folklore\Mediatheque\Pipelines;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;

/**
 * What a media is going through: its run in progress, as an editor's
 * interface shows it ("transcoding: 42 %"), from Media::processing()
 * (docs/decisions/0023).
 */
final readonly class Processing
{
    public function __construct(
        /** The media's status. */
        public MediaStatus $status,
        /** The uid of the run in progress. */
        public string $run,
        /** Its pipeline: `video`, `probe`… */
        public string $pipeline,
        /** Between 0 and 1, or null while nothing measured it. */
        public ?float $progress,
        /** The rendition that is running (`h264`), or null while none started. */
        public ?string $rendition,
    ) {}

    /**
     * The latest of these runs that hasn't ended, or null when they all
     * ended.
     *
     * @param  iterable<PipelineRun>  $runs  Oldest first
     */
    public static function of(Media $media, iterable $runs): ?self
    {
        $current = null;
        foreach ($runs as $run) {
            if (! $run->status()->isFinal()) {
                $current = $run;
            }
        }

        if ($current === null) {
            return null;
        }

        $rendition = null;
        foreach (array_keys($current->renditions()) as $name) {
            if ($current->job($name)?->status() === JobStatus::Running) {
                $rendition = $name;
                break;
            }
        }

        return new self($media->status(), $current->key(), $current->name(), $current->progress(), $rendition);
    }
}
