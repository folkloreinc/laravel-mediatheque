<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Folklore\Mediatheque\Contracts\Pipeline\Pipeline as PipelineDefinition;
use Exception;

interface Pipeline
{
    public function getName(): string;

    public function setDefinition(PipelineDefinition $definition): void;

    public function getDefinition(): PipelineDefinition;

    public function getJob(string $name): ?PipelineJob;

    public function addJob(PipelineJob $job): void;

    public function getMedia(): Media;

    public function allJobsEnded(): bool;

    public function hasFailedJobs(): bool;

    public function start(): void;

    public function markStarted(): void;

    public function markEnded(): void;

    public function markFailed(Exception $e = null): void;
}
