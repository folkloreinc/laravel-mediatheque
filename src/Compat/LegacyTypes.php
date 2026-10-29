<?php

namespace Folklore\Mediatheque\Compat;

use Folklore\Mediatheque\Contracts\Type\Factory;
use Folklore\Mediatheque\Contracts\Type\Type;
use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Folklore\Mediatheque\Ingest\ContentType;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Support\Collection;

/**
 * The 1.x type factory over the types of the 2.0 config:
 * `app(TypeFactory::class)->typeFromPath($path)->newModel()`, then
 * `setOriginalFile($path)`, ingests the file.
 *
 * @deprecated Use Mediatheque::ingest(). Removed in 3.0.
 */
final readonly class LegacyTypes implements Factory
{
    public function __construct(
        private MediathequeConfig $config,
    ) {}

    public function type(string $name): Type
    {
        self::deprecated();

        return new LegacyType($this->config->type($name));
    }

    public function hasType(string $name): bool
    {
        self::deprecated();

        try {
            $this->config->type($name);

            return true;
        } catch (InvalidConfig) {
            return false;
        }
    }

    public function typeFromPath(string $path): ?Type
    {
        self::deprecated();

        if (! is_file($path)) {
            return null;
        }

        $type = $this->config->detectType(ContentType::sniff($path));

        return $type !== null ? new LegacyType($type) : null;
    }

    public function types(): Collection
    {
        self::deprecated();

        $types = [];
        foreach ($this->config->types() as $type) {
            $types[] = new LegacyType($type);
        }

        return self::collect($types);
    }

    /**
     * @param  list<Type>  $types
     * @return Collection<int, Type>
     */
    private static function collect(array $types): Collection
    {
        return new Collection($types);
    }

    private static function deprecated(): void
    {
        Deprecation::trigger('The 1.x type factory (Contracts\Type\Factory) is deprecated: use Mediatheque::ingest(), which detects the type.');
    }
}
