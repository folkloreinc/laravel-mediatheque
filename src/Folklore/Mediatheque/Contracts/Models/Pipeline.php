<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineDefinition;
use Folklore\Mediatheque\Contracts\Support\HasPipelines;

interface Pipeline
{
    public function getName(): string;

    public function setDefinition(PipelineDefinition $definition): void;

    public function getDefinition(): PipelineDefinition;

    public function getJobs(): Collection;

    public function getJob(string $name): ?PipelineJob;

    public function addJob(PipelineJob $job): void;

    public function getModelToProcess(): HasPipelines;

    public function allJobsEnded(): bool;

    public function hasFailedJobs(): bool;

    public function start(): void;

    public function markStarted(): void;

    public function markEnded(): void;

    public function markFailed($e = null): void;
}
