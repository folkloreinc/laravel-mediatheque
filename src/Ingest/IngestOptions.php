<?php

namespace Folklore\Mediatheque\Ingest;

use Folklore\Mediatheque\Enums\Visibility;

/**
 * Options of one ingest. Null means the config's default.
 */
final readonly class IngestOptions
{
    /**
     * @param  string|null  $pipeline  The type's pipeline by default
     * @param  string|null  $source  The storage source of the original
     * @param  array<string, mixed>  $data  The media's application data
     * @param  array<string, mixed>  $attributes  Columns of the application's media model, such as a tenant id, set before the path is built (docs/decisions/0015)
     */
    public function __construct(
        public ?Visibility $visibility = null,
        public ?string $pipeline = null,
        public ?string $name = null,
        public ?string $source = null,
        public array $data = [],
        public array $attributes = [],
    ) {}
}
