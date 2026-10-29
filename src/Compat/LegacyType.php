<?php

namespace Folklore\Mediatheque\Compat;

use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Type\Type;
use Folklore\Mediatheque\Ingest\ContentType;
use Folklore\Mediatheque\Support\Models;
use Folklore\Mediatheque\Support\TypeDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * A type of the 2.0 config, as 1.x code reads it.
 *
 * @deprecated Use Mediatheque::ingest(). Removed in 3.0.
 */
final readonly class LegacyType implements Type
{
    public function __construct(
        private TypeDefinition $type,
    ) {}

    public function name(): string
    {
        return $this->type->name;
    }

    public function model(): string
    {
        return Models::media();
    }

    public function newModel(): Media
    {
        $class = Models::media();
        $media = new $class;
        $media->setAttribute('type', $this->type->name);

        return $media;
    }

    public function newQuery(): Builder
    {
        return Models::media()::query()->where('type', $this->type->name);
    }

    public function pathIsType(string $path): bool
    {
        return is_file($path) && $this->type->accepts(ContentType::sniff($path));
    }
}
