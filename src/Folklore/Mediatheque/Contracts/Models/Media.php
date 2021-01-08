<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Folklore\Mediatheque\Contracts\Type\Type;
use Folklore\Mediatheque\Contracts\Support\HasFiles;
use Folklore\Mediatheque\Contracts\Support\HasMetadatas;
use Folklore\Mediatheque\Contracts\Support\HasPipelines;
use Folklore\Mediatheque\Contracts\Support\HasThumbnails;
use Folklore\Mediatheque\Contracts\Support\HasUrl;

interface Media extends HasFiles, HasMetadatas, HasPipelines, HasThumbnails, HasUrl
{
    public function getType(): Type;

    public function setType($type): void;
}
