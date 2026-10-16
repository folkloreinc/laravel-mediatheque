<?php

namespace Folklore\Mediatheque\Enums;

/**
 * Why ingest refused a file.
 */
enum RejectionReason: string
{
    /** The detected mime matches no type. */
    case UnknownType = 'unknown_type';

    /** The type doesn't allow the detected mime. */
    case MimeNotAllowed = 'mime_not_allowed';

    /** Larger than the type's maximum size. */
    case TooLarge = 'too_large';

    /** An SVG that can't be sanitized. */
    case InvalidSvg = 'invalid_svg';

    /** The source can't be read: missing file, unreachable disk or URL. */
    case Unreadable = 'unreadable';

    /** A URL that the SSRF guard refuses. */
    case ForbiddenUrl = 'forbidden_url';
}
