<?php

namespace Folklore\Mediatheque\Metadata;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;

class Values extends Collection implements ValueContract
{
    public function getName(): ?string
    {
        return null;
    }

    public function getValue()
    {
        return $this;
    }

    public function getType(): string
    {
        return 'multiple';
    }
}
