<?php

namespace Folklore\Mediatheque\Compat;

/**
 * A metadata value as 1.x code reads it:
 * `$media->getMetadatas()->get('width')->getValue()`.
 *
 * @deprecated Read `$media->metadata('width')`. Removed in 3.0.
 */
final readonly class MetadataValue
{
    public function __construct(
        private string $name,
        private mixed $value,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
