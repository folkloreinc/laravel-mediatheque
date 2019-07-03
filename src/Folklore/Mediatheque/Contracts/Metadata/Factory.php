<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

use Imagine\Image\ImageInterface;

interface Factory
{
    public function metadata($name);

    public function hasMetadata($name);

    public function getMetadatas();
}
