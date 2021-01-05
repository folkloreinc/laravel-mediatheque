<?php

namespace Folklore\Mediatheque\Models\Collections;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HigherOrderCollectionProxy;

class MetadatasCollection extends Collection
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

        return $this->first(function ($item) use ($key) {
            return $item->name === $key;
        });
    }

    public function toMetadataArray()
    {
        return $this->reduce(function ($map, $metadata) {
            $name = $metadata->name;
            return array_merge($map, [
                $name => $metadata->value(),
            ]);
        }, []);
    }
}
