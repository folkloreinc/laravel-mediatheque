<?php

namespace Folklore\Mediatheque\Transcoders\Ffmpeg;

use Folklore\Mediatheque\Pipelines\Rendition;

/**
 * An operation of the `ffmpeg` driver, in Eclair's vocabulary
 * (`video.transcode`): it reads a local file and writes its outputs to a
 * local directory.
 */
interface Operation
{
    /**
     * The extension of the files it writes for this rendition.
     */
    public function extension(Rendition $rendition): string;

    /**
     * Produce the rendition's files in the context's output directory.
     *
     * @return list<LocalOutput>
     *
     * @throws OperationFailed
     */
    public function run(OperationContext $context): array;
}
