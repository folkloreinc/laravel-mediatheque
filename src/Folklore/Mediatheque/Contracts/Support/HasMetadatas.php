<?php

namespace Folklore\Mediatheque\Contracts\Support;

use Folklore\Mediatheque\Contracts\Metadata\Value as MetadataValue;
use Folklore\Mediatheque\Contracts\Models\Metadata;
use Illuminate\Support\Collection;

interface HasMetadatas
{
    public function getMetadatas(): Collection;

    public function getMetadata(string $name): ?Metadata;

    public function setMetadata(MetadataValue $value);

    public function setMetadatas(Collection $values);
}
