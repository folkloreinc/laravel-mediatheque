<?php

namespace Folklore\Mediatheque\Models\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HigherOrderCollectionProxy;

class FilesCollection extends Collection
{
    /*
    *
    * Get a specific handle
    *
    */
    public function __get($key)
    {
        if (in_array($key, static::$proxies)) {
            return new HigherOrderCollectionProxy($this, $key);
        }

        return $this->first(function ($item, $index) use ($key) {
            if (!is_object($item)) {
                $item = $index;
            }
            return ($item->pivot && $item->pivot->handle === $key) || $item->handle === $key;
        });
    }
}
