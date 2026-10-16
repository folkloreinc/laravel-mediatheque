<?php

namespace Folklore\Mediatheque\Enums;

/**
 * The kind of a file, as every prober and transcoder reports it. Rendition
 * conditions (`when.kind`) take these values, never the mediatheque's types.
 */
enum ProbeKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Audio = 'audio';
    case Document = 'document';
    case Font = 'font';
    case Other = 'other';
}
