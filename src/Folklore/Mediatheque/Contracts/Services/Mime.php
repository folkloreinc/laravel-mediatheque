<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Mime
{
    /**
     * Get the mime of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getMime($path);
}
