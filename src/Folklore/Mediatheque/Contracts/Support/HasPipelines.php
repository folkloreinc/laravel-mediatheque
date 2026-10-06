<?php

namespace Folklore\Mediatheque\Contracts\Support;

use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;
use Illuminate\Support\Collection;

interface HasPipelines extends HasFiles
{
    public function getPipelines(): Collection;

    public function getStartedPipelines(): Collection;

    public function hasPendingPipeline(string $name): bool;

    public function runPipeline($pipeline): ?PipelineContract;
}
