<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

use Illuminate\Support\Collection;

interface Factory
{
    public function metadata(string $name): Reader;

    public function hasMetadata(string $name): bool;
}
