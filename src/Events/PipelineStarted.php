<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;

/**
 * A pipeline run started: its first rendition was reported.
 */
final readonly class PipelineStarted
{
    public function __construct(
        public PipelineRun $run,
    ) {}

    public function media(): Media
    {
        return $this->run->media();
    }
}
