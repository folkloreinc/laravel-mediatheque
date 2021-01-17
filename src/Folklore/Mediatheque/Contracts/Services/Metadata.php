<?php

namespace Folklore\Mediatheque\Contracts\Services;

use Illuminate\Support\Collection;
use Imagine\Image\ImageInterface;
use Folklore\Mediatheque\Contracts\Type\Type;

interface Metadata
{
    /**
     * Get the metadata of a file
     *
     * @param  string  $path
     * @return Collection
     */
    public function getMetadata(string $path, ?Type $type = null): Collection;
}
