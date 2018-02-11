<?php

namespace Folklore\Mediatheque\Jobs\Document;

use Folklore\Mediatheque\Support\ThumbnailsJob;

class Thumbnails extends ThumbnailsJob
{
    protected $type = 'document';

    protected $defaultOptions = [

    ];
}
