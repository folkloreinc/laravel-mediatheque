<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Folklore\Mediatheque\Contracts\Metadata\Value;

interface Media
{
    public function fillFromValue(Value $value);

    public function value();
}
