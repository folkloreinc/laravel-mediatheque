<?php

namespace Folklore\Mediatheque\Contracts\Services;

use Folklore\Mediatheque\Contracts\Type\Type;
use Illuminate\Support\Collection;

interface Metadata
{
    /**
     * Get the metadata of a file
     */
    public function getMetadata(string $path, ?Type $type = null): Collection;
}
