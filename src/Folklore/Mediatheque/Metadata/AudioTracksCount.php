<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Services\AudioTracks as AudioTracksService;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class AudioTracksCount extends Reader
{
    protected $name = 'audio_tracks_count';

    public function getValue(string $path): ?ValueContract
    {
        $value = app(AudioTracksService::class)->getAudioTracks($path);
        return !is_null($value)
            ? new Value($this->getName(), $value->count(), 'integer')
            : null;
    }
}
