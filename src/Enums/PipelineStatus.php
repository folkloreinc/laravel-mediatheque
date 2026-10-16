<?php

namespace Folklore\Mediatheque\Enums;

/**
 * Status of a pipeline run. Statuses only move forward: pending, running,
 * then one final status.
 */
enum PipelineStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isFinal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Canceled => true,
            self::Pending, self::Running => false,
        };
    }

    /**
     * Whether a run can move from this status to another one. Moving to the
     * same status, back, or away from a final status is not allowed.
     */
    public function canMoveTo(self $status): bool
    {
        return match ($this) {
            self::Pending => $status !== self::Pending,
            self::Running => $status->isFinal(),
            self::Completed, self::Failed, self::Canceled => false,
        };
    }
}
