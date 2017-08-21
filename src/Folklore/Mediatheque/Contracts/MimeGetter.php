<?php

namespace Folklore\Mediatheque\Contracts;

interface MimeGetter
{
    /**
     * Get the mime type of a file
     *
     * @param  string  $path
     * @return float
     */
    public function getMime($path);
}
