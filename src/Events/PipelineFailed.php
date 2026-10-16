<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;

/**
 * Every rendition of a run ended, and at least one failed.
 */
final readonly class PipelineFailed
{
    public function __construct(
        public PipelineRun $run,
    ) {}

    public function media(): Media
    {
        return $this->run->media();
    }
}
