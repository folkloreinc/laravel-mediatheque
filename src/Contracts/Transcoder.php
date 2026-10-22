<?php

namespace Folklore\Mediatheque\Contracts;

use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Transcoders\Capabilities;

/**
 * Produces the renditions of a pipeline run (docs/decisions/0005, 0012).
 *
 * The contract is asynchronous: a driver receives a whole run, starts it,
 * and reports each rendition through the Recorder, now or later (queued
 * jobs, webhooks, polling). The local `ffmpeg` driver is the special case
 * that runs in the application's queue.
 *
 * - One transcoder runs a whole run. A run's renditions are closed under
 *   `from`: every rendition it reads from is in the run.
 * - A run is never retried in place. Retrying is a new run, with its own
 *   driver job.
 * - Remote drivers write under the run's path prefix, on its output source,
 *   with its visibility, and ignore `queue`.
 */
interface Transcoder
{
    /**
     * Whether this driver can run an operation (`video.transcode`).
     */
    public function supports(string $operation): bool;

    public function capabilities(): Capabilities;

    /**
     * Start the run. Returns the driver's job id, if it has one; the run
     * records it as its external id.
     */
    public function submit(PipelineRun $run): ?string;

    /**
     * With the cancel capability: stop the run. The driver reports the
     * renditions it canceled through the recorder. When a media's original
     * is replaced, its renditions are already canceled when this is called:
     * the driver stops its job all the same.
     */
    public function cancel(PipelineRun $run): void;

    /**
     * With the refresh capability: fetch the run's state from the driver and
     * report it through the recorder. `mediatheque:refresh` calls it for runs
     * that have been running too long, and for drivers that report progress
     * only by polling.
     */
    public function refresh(PipelineRun $run): void;
}
