<?php

namespace Folklore\Mediatheque\Jobs\Audio;

use FFMpeg\Format\Audio\Mp3 as Mp3Format;

class Mp3 extends FFMpegJob
{
    protected $format = Mp3Format::class;

    protected $defaultOptions = [
        'extension' => 'mp3',
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

    protected function getAdditionalParameters()
    {
        $parameters = parent::getAdditionalParameters();
        return $parameters;
    }
}
