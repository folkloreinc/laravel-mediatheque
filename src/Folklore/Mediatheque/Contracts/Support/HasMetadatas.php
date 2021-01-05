<?php
namespace Folklore\Mediatheque\Contracts\Support;

use Folklore\Mediatheque\Metadata\ValuesCollection;

interface HasMetadatas
{
    public function metadatas();

    public function metadata($name);

    public function setMetadata(ValuesCollection $values);
}
