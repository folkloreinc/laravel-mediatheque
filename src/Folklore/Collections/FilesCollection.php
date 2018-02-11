<?php namespace Folklore\Mediatheque\Collections;

use Illuminate\Database\Eloquent\Collection;

class FilesCollection extends Collection
{
    /*
    *
    * Get a specific handle
    *
    */
    public function __get($key)
    {
        return $this->first(function ($item) use ($key) {
            return ($item->pivot && $item->pivot->handle === $key) || $item->handle === $key;
        });
    }
}
