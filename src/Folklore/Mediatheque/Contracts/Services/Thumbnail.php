<?php

namespace Folklore\Mediatheque\Contracts\Services;

interface Thumbnail
{
    /**
     * Get the thumbnail of a path
     * @param  string $source The source path
     * @param  string $destination The destination path
     * @param  array $options The options
     * @return string The path of the thumbnail
     */
    public function getThumbnail($source, $destination, $options = []);
}
