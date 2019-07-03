<?php

namespace Folklore\Mediatheque\Models\Collections;

use Illuminate\Database\Eloquent\Collection;

class MetadatasCollection extends Collection
{
    /*
    *
    * Get a specific handle
    *
    */
    public function __get($key)
    {
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
