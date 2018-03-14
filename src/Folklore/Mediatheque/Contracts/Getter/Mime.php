<?php

namespace Folklore\Mediatheque\Contracts\Getter;

interface Mime
{
    /**
     * Get the mime type of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getMime($path);
}
