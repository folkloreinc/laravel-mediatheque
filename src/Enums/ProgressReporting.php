<?php

namespace Folklore\Mediatheque\Enums;

/**
 * How a transcoder reports the progress of its renditions.
 */
enum ProgressReporting: string
{
    /** No progress, only statuses. */
    case None = 'none';

    /** Through the recorder, as it happens (local jobs, webhooks). */
    case Pushed = 'pushed';

    /** Only when `mediatheque:refresh` asks for it. */
    case Polled = 'polled';
}
