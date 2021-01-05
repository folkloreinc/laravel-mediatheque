<?php
namespace Folklore\Mediatheque\Support\Traits;

trait HasThumbnails
{
    public function getThumbnails()
    {
        return $this->files->filter(function ($item) {
            return $item->pivot && preg_match('/^thumbnail(?::\d*)?/', $item->pivot->handle) === 1;
        })->values();
    }

    /**
     *
     * Accessors and mutators
     *
     */
    protected function getThumbnailsAttribute()
    {
        return $this->getThumbnails();
    }
}
