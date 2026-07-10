<?php

namespace Folklore\Mediatheque\Jobs\Video\Formats;

use FFMpeg\Format\Video\WebM as BaseWebM;

class WebM extends BaseWebM
{
    public function getAvailableAudioCodecs()
    {
        return array_merge(parent::getAvailableAudioCodecs(), ['libopus']);
    }
}
