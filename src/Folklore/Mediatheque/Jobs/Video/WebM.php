<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Support\FFMpegJob;
use FFMpeg\Format\Video\WebM as WebMFormat;

class WebM extends FFMpegJob
{
    protected $format = WebMFormat::class;

    protected $defaultOptions = [
        'quality' => 20,
        'extension' => '.webm'
    ];
}
