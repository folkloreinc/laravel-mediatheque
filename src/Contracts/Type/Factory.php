<?php

namespace Folklore\Mediatheque\Contracts\Type;

use Illuminate\Support\Collection;

/**
 * The type factory of 1.x, bound by the compatibility layer
 * (Compat\LegacyTypes). Types are read from the config in 2.0, and detected
 * by ingest.
 *
 * @deprecated Use Mediatheque::ingest(), which detects the type. Removed in 3.0.
 */
interface Factory
{
    public function type(string $name): Type;

    public function hasType(string $name): bool;

    /**
     * The type of a local file, from its content.
     */
    public function typeFromPath(string $path): ?Type;

    /**
     * @return Collection<int, Type>
     */
    public function types(): Collection;
}
