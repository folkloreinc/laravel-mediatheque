<?php

namespace Folklore\Mediatheque\Contracts;

interface ExtensionGetter
{
    /**
     * Get the extension of a file
     *
     * @param  string  $path
     * @return string
     */
    public function getExtension($path, $filename = null);
}
