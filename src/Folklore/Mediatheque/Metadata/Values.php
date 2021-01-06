<?php

namespace Folklore\Mediatheque\Metadata;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Metadata\Values as ValuesContract;

class Values extends Collection implements ValuesContract
{
    public function getName(): ?string
    {
        return null;
    }

    public function getValue(): Collection
    {
        return $this;
    }

    public function getType(): string
    {
        return 'multiple';
    }
}
