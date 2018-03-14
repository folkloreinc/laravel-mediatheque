<?php

namespace Folklore\Mediatheque\Contracts\Getter;

interface Extension
{
    /**
     * Get the extension of a file
     *
     * @param  string  $path
     * @return string
     */
    public function getExtension($path, $filename = null);
}
