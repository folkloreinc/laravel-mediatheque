<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;

/**
 * A rendition was canceled.
 */
final readonly class RenditionCanceled
{
    public function __construct(
        public PipelineJob $job,
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
