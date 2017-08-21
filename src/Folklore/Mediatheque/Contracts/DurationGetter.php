<?php

namespace Folklore\Mediatheque\Contracts;

interface DurationGetter
{
    /**
     * Get duration of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getDuration($path);
}
