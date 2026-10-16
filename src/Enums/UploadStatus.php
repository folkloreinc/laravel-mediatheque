<?php

namespace Folklore\Mediatheque\Enums;

/**
 * Status of a presigned multipart upload.
 */
enum UploadStatus: string
{
    /** Created: its parts are being uploaded. */
    case Pending = 'pending';
    /** Completed and ingested: it has a media. */
    case Completed = 'completed';
    /** Aborted by its creator, or rejected by ingest. */
    case Aborted = 'aborted';
}
