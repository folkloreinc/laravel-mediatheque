<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;

/**
 * A rendition's progress passed a multiple of `events.progress_step`
 * (docs/decisions/0023). Never dispatched for a call that changes nothing,
 * nor for a status change, which has its own event. The package doesn't
 * broadcast it: an application that wants it live broadcasts it from its
 * own listener, on channels it authorizes.
 */
final readonly class RenditionProgressed
{
    public function __construct(
        public PipelineJob $job,
        /** The rendition's progress, between 0 and 1. */
        public float $progress,
        /** The run's progress, weighted (Pipelines\Progress). */
        public float $runProgress,
    ) {}

    public function media(): Media
    {
        return $this->job->run()->media();
    }

    public function run(): PipelineRun
    {
        return $this->job->run();
    }

    public function rendition(): string
    {
        return $this->job->rendition()->name;
    }
}
