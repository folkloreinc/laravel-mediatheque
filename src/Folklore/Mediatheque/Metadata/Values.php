<?php

namespace Folklore\Mediatheque\Metadata;

use Folklore\Mediatheque\Contracts\Metadata\Value as ValueContract;
use Illuminate\Support\Collection;

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
