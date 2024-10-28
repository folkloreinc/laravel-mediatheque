<?php

namespace Folklore\Mediatheque\Jobs\Video\Formats;

use FFMpeg\Format\Video\X264;

class HEVC extends X264
{
    public function __construct($audioCodec = 'aac', $videoCodec = 'libx265')
    {
        $this->setAudioCodec($audioCodec)->setVideoCodec($videoCodec);
    }

    public function getAvailableVideoCodecs()
    {
        return ['libx265'];
    }
}
