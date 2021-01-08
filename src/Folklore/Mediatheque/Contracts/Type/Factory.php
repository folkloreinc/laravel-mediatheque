<?php

namespace Folklore\Mediatheque\Contracts\Type;

use Illuminate\Support\Collection;

interface Factory
{
    public function type(string $name): Type;

    public function hasType(string $name): bool;

    public function typeFromPath(string $path): ?Type;

    public function types(): Collection;
}
