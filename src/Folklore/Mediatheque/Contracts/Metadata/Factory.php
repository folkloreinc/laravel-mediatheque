<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

interface Factory
{
    public function metadata(string $name): Reader;

    public function hasMetadata(string $name): bool;
}
