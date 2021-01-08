<?php

namespace Folklore\Mediatheque\Contracts\Source;

interface Factory
{
    public function source($name): Source;

    public function hasSource($name): bool;
}
