<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Duration
{
    /**
     * Get the duration of a path
     * @param  string $path The path of a file
     * @return float The duration in seconds
     */
    public function getDuration($path);
}
