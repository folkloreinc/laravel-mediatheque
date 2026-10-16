<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;

/**
 * Every rendition of a run ended, none failed or was canceled.
 */
final readonly class PipelineCompleted
{
    public function __construct(
        public PipelineRun $run,
    ) {}

    public function media(): Media
    {
        return $this->run->media();
    }
}
