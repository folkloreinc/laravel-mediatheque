<?php

namespace Folklore\Mediatheque\Contracts\Getter;

interface Metadata
{
    /**
     * Get metadata of a file
     *
     * @param  string  $path
     * @param  string  $type
     * @return array
     */
    public function getMetadata($path);
}
