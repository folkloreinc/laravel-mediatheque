<?php
namespace Folklore\Mediatheque\Contracts\Support;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Models\Metadata;
use Folklore\Mediatheque\Contracts\Metadata\Value as MetadataValue;

interface HasMetadatas
{
    public function getMetadatas(): Collection;

    public function getMetadata(string $name): ?Metadata;

    public function setMetadata(MetadataValue $value);

    public function setMetadatas(Collection $values);
}
