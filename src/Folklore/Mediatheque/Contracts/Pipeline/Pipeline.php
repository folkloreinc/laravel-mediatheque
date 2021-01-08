<?php

namespace Folklore\Mediatheque\Contracts\Pipeline;

use Illuminate\Support\Collection;

interface Pipeline
{
    public function name(): string;

    public function autoStart(): bool;

    public function unique(): bool;

    public function shouldQueue(): bool;

    public function fromFile(): ?string;

    public function jobs(): Collection;
}
