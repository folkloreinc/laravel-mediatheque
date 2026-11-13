<?php

namespace Folklore\Mediatheque\Enums;

/**
 * What importing an embed's file makes of it (docs/decisions/0030).
 */
enum ImportMode: string
{
    /** The file stays a rendition of the embed, which keeps playing from its platform. */
    case Rendition = 'rendition';

    /**
     * The file replaces the embed's remote original: the media becomes an
     * ordinary video or audio, probed again, and runs its type's pipeline.
     */
    case ReplaceOriginal = 'original';
}
