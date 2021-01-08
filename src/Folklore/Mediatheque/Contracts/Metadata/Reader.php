<?php

namespace Folklore\Mediatheque\Contracts\Metadata;

interface Reader
{
    public function setName(string $name): void;

    public function getName(): string;

    public function getValue($path): ?Value;
}
