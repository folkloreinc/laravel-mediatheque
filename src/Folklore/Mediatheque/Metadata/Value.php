<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class Value implements ValueContract
{
    protected $name;
    protected $value;
    protected $type;

    public function __construct($name, $value, $type = null)
    {
        $this->name = $name;
        $this->value = $value;
        $this->type = $type;
    }

    public function getName()
    {
        return $this->name;
    }

    public function getValue()
    {
        return $this->value;
    }

    public function getType()
    {
        return $this->type;
    }
}
