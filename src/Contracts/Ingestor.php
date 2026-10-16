<?php

namespace Folklore\Mediatheque\Contracts;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Exceptions\FileRejected;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;

/**
 * The one step every way in ends in (docs/decisions/0006).
 *
 * 1. Detect the type from the content, never from the client's file name or
 *    extension; check the type's maximum size and allowed mimes. A file that
 *    matches no type is rejected.
 * 2. Sanitize SVG; remove GPS data from images.
 * 3. Build the path with the path formatter, which receives the media.
 * 4. Store the original, create the media (`pending`) and dispatch
 *    `MediaCreated`.
 * 5. Queue the probe; the pipeline follows `MediaProbed`.
 *
 * A rejected file leaves neither a stored file nor a row behind. A URL
 * source is checked by the SSRF guard, then downloaded in a queued job: the
 * media is returned `pending`, and gets its original when the download ends.
 */
interface Ingestor
{
    /**
     * @throws FileRejected
     */
    public function ingest(Source $source, IngestOptions $options = new IngestOptions): Media;
}
