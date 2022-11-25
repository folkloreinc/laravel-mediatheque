<?php

namespace Folklore\Mediatheque\Jobs\Video;

use Folklore\Mediatheque\Support\FFMpegJob;
use FFMpeg\Format\Video\WebM as WebMFormat;

class WebM extends FFMpegJob
{
    protected $format = WebMFormat::class;

    protected $defaultOptions = [
        'quality' => 26,
        'extension' => 'webm',
    ];

    protected function getAdditionalParameters()
    {
        $parameters = parent::getAdditionalParameters();

        $parameters[] = '-deadline';
        $parameters[] = 'realtime';

        if ($this->file->mime === 'image/gif') {
            $parameters[] = '-auto-alt-ref';
            $parameters[] = '0';
        }

        return $parameters;
    }
}
