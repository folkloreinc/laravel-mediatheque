<?php

namespace Folklore\Mediatheque\Transcoders;

use Folklore\Mediatheque\Enums\ProgressReporting;
use InvalidArgumentException;

/**
 * Optional abilities of a transcoder. `mediatheque:doctor` reads them to
 * check the pipelines and the schedule.
 */
final readonly class Capabilities
{
    public function __construct(
        /** How it reports progress. */
        public ProgressReporting $progress = ProgressReporting::None,
        /** It can cancel a run it started. */
        public bool $cancel = false,
        /** It can fetch a run's state on demand: `refresh()`, called by `mediatheque:refresh`. */
        public bool $refresh = false,
        /** It can probe files, as a `Prober`. */
        public bool $probe = false,
        /** Its renditions can stay with a provider, played from the provider's URLs. */
        public bool $remoteRenditions = false,
    ) {
        if ($progress === ProgressReporting::Polled && ! $refresh) {
            throw new InvalidArgumentException('A transcoder that reports progress by polling must support refresh.');
        }
    }

    public static function none(): self
    {
        return new self;
    }

    /**
     * Whether `mediatheque:refresh` must be scheduled for this transcoder.
     */
    public function needsRefreshSchedule(): bool
    {
        return $this->refresh;
    }
}
