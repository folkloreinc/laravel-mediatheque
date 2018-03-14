<?php

namespace Folklore\Mediatheque\Contracts\Getter;

interface Duration
{
    /**
     * Get duration of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getDuration($path);
}
