<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Folklore\Mediatheque\Contracts\Metadata\Value;

interface Metadata
{
    public function getName(): string;

    public function setValue(Value $value);

    public function getValue();
}
