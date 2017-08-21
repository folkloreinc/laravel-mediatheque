<?php

namespace Folklore\Mediatheque\Contracts;

interface DimensionGetter
{
    /**
     * Get duration of a file
     *
     * @param  string  $path
     * @return array
     */
    public function getDimension($path);
}
