<?php

namespace Folklore\Mediatheque\Contracts\Type;

interface Factory
{
    public function typeFromPath($path);

    public function type($name);

    public function hasType($name);

    public function types();

    public function getTypes();
}
