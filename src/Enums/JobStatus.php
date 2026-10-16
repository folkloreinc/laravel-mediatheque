<?php

namespace Folklore\Mediatheque\Enums;

/**
 * Status of a pipeline job, the production of one rendition in a run.
 * Statuses only move forward: queued, running, then one final status. A
 * driver can report a final status without reporting running first.
 */
enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /** A condition did not match the input, or the rendition it reads from was skipped. Not a failure. */
    case Skipped = 'skipped';

    case Canceled = 'canceled';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Skipped, self::Canceled => true,
            self::Queued, self::Running => false,
        };
    }

    /**
     * Whether a job can move from this status to another one. Moving to the
     * same status, back, or away from a final status is not allowed.
     */
    public function canMoveTo(self $status): bool
    {
        return match ($this) {
            self::Queued => $status !== self::Queued,
            self::Running => $status->isFinal(),
            self::Completed, self::Failed, self::Skipped, self::Canceled => false,
        };
    }
}
