<?php
namespace Folklore\Mediatheque\Contracts\Support;

use Illuminate\Support\Collection;

interface HasThumbnails
{
    public function getThumbnails(): Collection;
}
