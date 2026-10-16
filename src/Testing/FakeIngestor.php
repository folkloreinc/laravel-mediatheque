<?php

namespace Folklore\Mediatheque\Testing;

use Folklore\Mediatheque\Contracts\Ingestor;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Ingest\IngestOptions;
use Folklore\Mediatheque\Ingest\Source;

/**
 * The Ingestor of the fake, for code that depends on the Ingestor contract.
 */
class FakeIngestor implements Ingestor
{
    public function __construct(private readonly MediathequeFake $fake) {}

    public function ingest(Source $source, IngestOptions $options = new IngestOptions): Media
    {
        return $this->fake->ingestSource($source, $options);
    }
}
