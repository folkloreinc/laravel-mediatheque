<?php

namespace Folklore\Mediatheque\Contracts\Services;

use Illuminate\Support\Collection;
use Imagine\Image\ImageInterface;

interface Metadata
{
    /**
     * Get the metadata of a file
     *
     * @param  string  $path
     * @return array
     */
    public function getMetadata($path, $type = null): Collection;
}
