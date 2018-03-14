<?php

namespace Folklore\Mediatheque\Contracts\Getter;

interface Dimension
{
    /**
     * Get duration of a file
     *
     * @param  string  $path
     * @return array
     */
    public function getDimension($path);
}
