<?php
namespace Folklore\Mediatheque\Support\Traits;

use Illuminate\Support\Collection;

trait HasThumbnails
{
    public function getThumbnails(): Collection
    {
        return $this->getFiles()
            ->filter(function ($item) {
                $handle = $item->getHandle();
                return isset($handle) && preg_match('/^thumbnail(?::\d*)?/', $handle) === 1;
            })
            ->values();
    }
}
