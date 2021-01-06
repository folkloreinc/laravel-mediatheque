<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

use Illuminate\Support\Collection;

interface Values extends Value
{
    public function getValue(): Collection;
}
