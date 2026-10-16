<?php

namespace Folklore\Mediatheque\Enums;

enum MediaStatus: string
{
    /** Ingested, waiting for its probe. */
    case Pending = 'pending';

    /** Probed, its pipeline is running. */
    case Processing = 'processing';

    /** Every rendition of its pipeline ended, and its main rendition completed. */
    case Ready = 'ready';

    /** Its probe failed, or its main rendition did not complete. */
    case Failed = 'failed';
}
