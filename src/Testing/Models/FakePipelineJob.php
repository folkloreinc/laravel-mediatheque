<?php

namespace Folklore\Mediatheque\Testing\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\TranscoderError;

/**
 * An in-memory pipeline job of the fake.
 */
class FakePipelineJob implements PipelineJob
{
    public JobStatus $status = JobStatus::Queued;

    public float $progress = 0.0;

    public int $attempts = 0;

    public ?TranscoderError $error = null;

    public ?string $reason = null;

    public ?CarbonImmutable $queuedAt = null;

    public ?CarbonImmutable $startedAt = null;

    public ?CarbonImmutable $endedAt = null;

    public function __construct(
        public FakePipelineRun $run,
        public Rendition $rendition,
    ) {}

    public function run(): PipelineRun
    {
        return $this->run;
    }

    public function rendition(): Rendition
    {
        return $this->rendition;
    }

    public function status(): JobStatus
    {
        return $this->status;
    }

    public function progress(): float
    {
        return $this->progress;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function error(): ?TranscoderError
    {
        return $this->error;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }

    public function queuedAt(): ?CarbonInterface
    {
        return $this->queuedAt;
    }

    public function startedAt(): ?CarbonInterface
    {
        return $this->startedAt;
    }

    public function endedAt(): ?CarbonInterface
    {
        return $this->endedAt;
    }
}
