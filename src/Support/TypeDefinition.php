<?php

namespace Folklore\Mediatheque\Support;

use Illuminate\Support\Str;

/**
 * A media type, as the config declares it: the mimes it accepts, its
 * pipeline and its maximum size.
 */
final readonly class TypeDefinition
{
    /**
     * @param  list<string>  $mimes  Patterns, such as `video/*`
     * @param  string|null  $pipeline  Null when the type has no pipeline
     * @param  int|null  $maxSize  In bytes; null for no limit
     */
    public function __construct(
        public string $name,
        public array $mimes,
        public ?string $pipeline,
        public ?int $maxSize,
    ) {}

    /**
     * Whether a mime sniffed from the content belongs to this type.
     */
    public function accepts(string $mime): bool
    {
        return Str::is($this->mimes, strtolower($mime));
    }
}
