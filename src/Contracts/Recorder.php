<?php

namespace Folklore\Mediatheque\Contracts;

use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Pipelines\Outputs;
use Folklore\Mediatheque\Transcoders\TranscoderError;

/**
 * Where transcoders report each rendition (docs/decisions/0012).
 *
 * Every call is idempotent and statuses only move forward: `queued` →
 * `running` → `completed`, `failed`, `skipped` or `canceled`. A call that
 * would move a rendition back, or change a final status, is ignored, and
 * progress never goes down. A driver that receives a whole snapshot on every
 * webhook reports every rendition each time, in whatever order deliveries
 * arrive.
 *
 * - A failed rendition fails the renditions that read from it
 *   (`dependency_failed`); a skipped one skips them; independent renditions
 *   continue.
 * - The run ends when all its renditions have a final status: `failed` if
 *   one failed, else `canceled` if one was canceled, else `completed`.
 * - After a run of the media's pipeline ends, the media is `ready` if its
 *   main rendition completed, `failed` otherwise.
 */
interface Recorder
{
    public function started(PipelineJob $job): void;

    /**
     * @param  float  $progress  Between 0 and 1
     */
    public function progress(PipelineJob $job, float $progress): void;

    /**
     * Files and metadata outputs. A rendition with `keep: false` completes
     * with no file.
     */
    public function completed(PipelineJob $job, Outputs $outputs): void;

    public function skipped(PipelineJob $job, string $reason): void;

    public function failed(PipelineJob $job, TranscoderError $error): void;

    /**
     * A rendition the driver canceled on its own (fail-fast, an operator).
     */
    public function canceled(PipelineJob $job): void;
}
