<?php

namespace Folklore\Mediatheque\Contracts\Models;

use Carbon\CarbonInterface;
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
     * Between 0 and 1, or null while nothing measured it: a transcoder that
     * reports no progress, ffmpeg without the input's duration, an
     * operation that reports none. Never goes down. 1 once completed.
     */
    public function progress(): ?float;

    /**
     * Attempts of the `ffmpeg` driver's queued job.
     */
    public function attempts(): int;

    public function error(): ?TranscoderError;

    /**
     * Why it was skipped.
     */
    public function reason(): ?string;

    /**
     * When the rendition could run: when its run was created, or when the
     * rendition it reads from completed (docs/decisions/0017).
     */
    public function queuedAt(): ?CarbonInterface;

    public function startedAt(): ?CarbonInterface;

    /** When it reached a final status. */
    public function endedAt(): ?CarbonInterface;
}
