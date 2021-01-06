<?php

namespace Folklore\Mediatheque\Contracts\Pipeline;

interface Factory
{
    public function pipeline($name): Pipeline;

    public function hasPipeline($name): bool;
}
