<?php

namespace Folklore\Mediatheque\Jobs\Audio;

use Folklore\Mediatheque\Support\ThumbnailsJob;

class Thumbnails extends ThumbnailsJob
{
    protected $type = 'audio';

    protected $defaultOptions = [
        'zoom' => 600,
        'width' => 1200,
        'height' => 400,
        'axis_label' => false,
        'background_color' => 'FFFFFF00',
        'color' => '000000',
        'border_color' => null,
        'axis_label_color' => null,
    ];
}
