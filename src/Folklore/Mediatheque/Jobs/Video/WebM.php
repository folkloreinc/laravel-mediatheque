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
        'kilo_bitrate' => 0,
        // 'deadline' => 'realtime',
    ];

    protected function getAdditionalParameters()
    {
        $parameters = parent::getAdditionalParameters();

        $kiloBitrate = data_get($this->options, 'kilo_bitrate', null);
        if ($kiloBitrate === 0) {
            $parameters[] = '-b:v';
            $parameters[] = 0;
        }

        $deadline = data_get($this->options, 'deadline', null);
        if (!is_null($deadline)) {
            $parameters[] = '-deadline';
            $parameters[] = $deadline;
        }

        if ($this->file->mime === 'image/gif') {
            $parameters[] = '-auto-alt-ref';
            $parameters[] = '0';
        }

        return $parameters;
    }
}
