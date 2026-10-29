<?php

namespace Folklore\Mediatheque\Tests\Support;

/**
 * The properties of 1.x's `Support\Pipeline`, which `serialize()` wrote in
 * the `pipelines` table. V1Database renames the class in the serialized
 * string.
 */
final class V1SerializedPipeline
{
    /**
     * @param  array<string, mixed>  $jobs
     */
    public function __construct(
        protected string $name,
        protected array $jobs,
        protected bool $autoStart = true,
        protected bool $unique = false,
        protected bool|string $queue = true,
        protected string $fromFile = 'original',
    ) {}
}
