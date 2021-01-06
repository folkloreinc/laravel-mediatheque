<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

use Illuminate\Support\Collection;

interface Factory
{
    public function metadata($name): Reader;

    public function hasMetadata($name): bool;
}
