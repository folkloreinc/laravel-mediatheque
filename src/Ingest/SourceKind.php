<?php

namespace Folklore\Mediatheque\Ingest;

enum SourceKind: string
{
    case Upload = 'upload';
    case Path = 'path';
    case Stream = 'stream';
    case Disk = 'disk';
    case Url = 'url';
}
