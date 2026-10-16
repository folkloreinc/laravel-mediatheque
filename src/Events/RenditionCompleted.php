<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Pipelines\Outputs;

/**
 * A rendition completed. Drivers that report from webhook snapshots may dispatch it some time after the fact.
 */
final readonly class RenditionCompleted
{
    public function __construct(
        public PipelineJob $job,
        public Outputs $outputs,
    ) {}

    public function media(): Media
    {
        return $this->job->run()->media();
    }

    public function rendition(): string
    {
        return $this->job->rendition()->name;
    }
}
