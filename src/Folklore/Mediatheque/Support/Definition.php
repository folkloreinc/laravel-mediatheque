<?php

namespace Folklore\Mediatheque\Support;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Str;
use \JsonSerializable;

abstract class Definition implements JsonSerializable, Arrayable, Jsonable
{
    public function __construct($definition = [])
    {
        if (!is_null($definition)) {
            $this->setDefinition($definition);
        }
    }

    public function setDefinition($definition)
    {
        foreach ($definition as $key => $value) {
            $propertyName = Str::camel($key);
            if ($this->hasProperty($propertyName)) {
                $this->set($key, $value);
            }
        }
        return $this;
    }

    public function set($key, $value)
    {
        $this->{$key} = $value;
        return $this;
    }

    public function get($key)
    {
        return isset($this->{$key}) ? $this->{$key} : null;
    }

    protected function hasProperty($key)
    {
        return property_exists($this, $key);
    }

    abstract public function toArray();

    public function jsonSerialize()
    {
        return $this->toArray();
    }

    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }
}
