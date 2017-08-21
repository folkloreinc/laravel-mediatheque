<?php

namespace Folklore\Mediatheque\Contracts;

interface ThumbnailCreator
{
    /**
     * Create a thumbnail
     *
     * @param  string  $source
     * @param  string  $destination
     * @return string|boolean
     */
    public function createThumbnail($source, $destination);
}
