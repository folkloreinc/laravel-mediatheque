<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

use Folklore\Mediatheque\Pipelines\Rendition;

/**
 * An operation whose requirements are checked before its input is copied
 * to the worker: an optional binary, an option. A rendition that can't run
 * fails without downloading its input.
 */
interface ChecksRequirements
{
    /**
     * @throws OperationFailed
     */
    public function checkRequirements(Rendition $rendition): void;
}
