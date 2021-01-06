<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

interface Value
{
    public function getName(): ?string;

    public function getValue();

    public function getType(): string;
}
