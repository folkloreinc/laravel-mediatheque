<?php

namespace Folklore\Mediatheque\Contracts\Type;

use Illuminate\Support\Collection;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Pipeline\Pipeline;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;

interface Type
{
    public function name(): string;

    public function model(): string;

    public function newModel(): Media;

    public function newQuery(): QueryBuilder;

    public function metadatas(): Collection;

    public function pipeline(): ?Pipeline;

    public function canUpload(): bool;

    public function pathIsType(string $path): bool;
}
