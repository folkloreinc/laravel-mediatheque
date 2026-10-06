<?php

namespace Folklore\Mediatheque\Contracts\Services;

use Illuminate\Support\Collection;

interface AudioTracks
{
    /**
     * Get the pages count of a path
     *
     * @param  string  $path  The path of a file
     * @return int The number of pages
     */
    public function getAudioTracks(string $path): ?Collection;
}
