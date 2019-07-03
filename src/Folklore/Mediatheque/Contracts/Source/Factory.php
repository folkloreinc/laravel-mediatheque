<?php

namespace Folklore\Mediatheque\Contracts\Source;

interface Factory
{
    public function source($name);

    public function hasSource($name);

    public function getSources();
}
