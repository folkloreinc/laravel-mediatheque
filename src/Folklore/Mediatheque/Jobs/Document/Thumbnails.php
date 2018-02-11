<?php

namespace Folklore\Mediatheque\Jobs\Document;

use Folklore\Mediatheque\Support\ThumbnailsJob;

class Thumbnails extends ThumbnailsJob
{
    protected $type = 'document';

    protected $defaultOptions = [
        'count' => 'all',
        'resolution' => 150,
        'quality' => 100,
        'background' => 'white',
        'format' => 'jpeg',
        'font' => null,
    ];
}
