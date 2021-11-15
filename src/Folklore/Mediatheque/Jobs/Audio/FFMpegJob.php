<?php

namespace Folklore\Mediatheque\Jobs\Audio;

use Folklore\Mediatheque\Support\FFMpegJob as BaseFFMpegJob;

class FFMpegJob extends BaseFFMpegJob
{
    protected $defaultOptions = [
        'bitrate' => 196,
        'channels' => null,
    ];

    protected function getFormat()
    {
        $format = parent::getFormat();

        $bitrate = data_get($this->options, 'bitrate', null);
        if (!is_null($bitrate)) {
            $format->setAudioKiloBitrate($bitrate);
        }

        $channels = data_get($this->options, 'channels', null);
        if (!is_null($channels)) {
            $format->setAudioChannels($channels);
        }

        return $format;
    }
}
