<?php

namespace Folklore\Mediatheque\Contracts;

interface MetadataGetter
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
