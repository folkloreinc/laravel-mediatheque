<?php

namespace Folklore\Mediatheque\Models\Collections;

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
        return $this->first(function ($item, $index) use ($key) {
            if (!is_object($item)) {
                $item = $index;
            }
            return ($item->pivot && $item->pivot->handle === $key) || $item->handle === $key;
        });
    }
}
