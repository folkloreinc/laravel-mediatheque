<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Metadata\Reader as ReaderContract;

abstract class Reader implements ReaderContract
{
    protected $name;

    public function setName($name)
    {
        $this->name = $name;
        return $this;
    }

    public function getName()
    {
        return $this->name;
    }

    public function hasMultipleValues()
    {
        return false;
    }
}
