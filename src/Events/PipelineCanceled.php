<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;

/**
 * Every rendition of a run ended, at least one was canceled and none failed.
 */
final readonly class PipelineCanceled
{
    public function __construct(
        public PipelineRun $run,
    ) {}

    public function media(): Media
    {
        return $this->run->media();
    }
}
