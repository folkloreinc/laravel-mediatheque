<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;

/**
 * A rendition was skipped: its condition didn't match the input, or the rendition it reads from was skipped.
 */
final readonly class RenditionSkipped
{
    public function __construct(
        public PipelineJob $job,
        public string $reason,
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
