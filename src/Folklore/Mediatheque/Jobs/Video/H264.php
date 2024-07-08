<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Support\FFMpegJob;
use FFMpeg\Format\Video\X264;

class H264 extends FFMpegJob
{
    protected $format = X264::class;

    protected $defaultOptions = [
        'audio_codec' => 'aac',
        'passes' => 1,
        'quality' => 26,
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

    protected function getAdditionalParameters()
    {
        $parameters = parent::getAdditionalParameters();

        $audioCodec = data_get($this->options, 'audio_codec', 'aac');
        if ($audioCodec === 'aac') {
            $parameters[] = '-strict';
            $parameters[] = '-2';
        }

        return $parameters;
    }
}
