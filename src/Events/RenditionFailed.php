<?php

namespace Folklore\Mediatheque\Events;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineJob;
use Folklore\Mediatheque\Transcoders\TranscoderError;

/**
 * A rendition failed, or the rendition it reads from failed (`dependency_failed`).
 */
final readonly class RenditionFailed
{
    public function __construct(
        public PipelineJob $job,
        public TranscoderError $error,
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
