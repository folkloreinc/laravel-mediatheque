<?php

namespace Folklore\Mediatheque\Jobs\Audio;

use Folklore\Mediatheque\Support\ThumbnailsJob;

class Thumbnails extends ThumbnailsJob
{
    protected $type = 'audio';

    protected $defaultOptions = [

    ];
}
