<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Pipelines\Rendition;
use Folklore\Mediatheque\Transcoders\TranscoderError;

/**
 * The production of one rendition in a pipeline run.
 */
interface PipelineJob
{
    public function run(): PipelineRun;

    public function rendition(): Rendition;

    public function status(): JobStatus;

    /**
     * Between 0 and 1. Never goes down.
     */
    public function progress(): float;

    /**
     * Attempts of the `ffmpeg` driver's queued job.
     */
    public function attempts(): int;

    public function error(): ?TranscoderError;

    /**
     * Why it was skipped.
     */
    public function reason(): ?string;
}
