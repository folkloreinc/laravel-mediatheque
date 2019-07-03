<?php

namespace Folklore\Mediatheque\Contracts\Pipeline;

interface Factory
{
    public function pipeline($name);

    public function hasPipeline($name);

    public function getPipelines();
}
