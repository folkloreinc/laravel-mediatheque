<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Support\ThumbnailsJob;

class Thumbnails extends ThumbnailsJob
{
    protected $type = 'video';

    protected $defaultOptions = [

    ];
}
