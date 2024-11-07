<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Support\FFMpegJob;
use Folklore\Mediatheque\Jobs\Video\Formats\HEVC as HEVCFormat;

class HEVC extends H264
{
    protected $format = HEVCFormat::class;

    protected $defaultOptions = [
        'audio_codec' => 'aac',
        'passes' => 1,
        'quality' => 30,
        'extension' => 'mp4',
        'parameters' => [
            '-y',
            '-preset',
            'fast',
            '-pix_fmt',
            'yuv420p',
            '-profile:v',
            'main',
            '-movflags',
            '+faststart',
        ],
    ];
}
