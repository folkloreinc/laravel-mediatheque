<?php

namespace Folklore\Mediatheque\Contracts\Services;

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
