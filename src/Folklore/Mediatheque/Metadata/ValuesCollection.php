<?php

namespace Folklore\Mediatheque\Metadata;

use Illuminate\Support\Collection;
use Illuminate\Contracts\Support\Arrayable;

class ValuesCollection extends Collection
{
    public function toArray()
    {
        return $this->reduce(function ($data, $value) {
            $name = $value->getName();
            $value = $value->getValue();
            return array_merge($data, [
                $name => [
                    'type' => $value->getType(),
                    'value' => $value instanceof Arrayable ? $value->toArray() : $value,
                ]
            ]);
        }, []);
    }
}
