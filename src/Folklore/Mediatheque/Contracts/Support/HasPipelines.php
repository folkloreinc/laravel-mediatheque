<?php
namespace Folklore\Mediatheque\Contracts\Support;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Models\Pipeline as PipelineContract;

interface HasPipelines extends HasFiles
{
    public function getPipelines(): Collection;

    public function getStartedPipelines(): Collection;

    public function hasPendingPipeline(string $name): bool;

    public function runPipeline($pipeline): ?PipelineContract;
}
