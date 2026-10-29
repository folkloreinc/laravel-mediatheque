<?php

namespace Folklore\Mediatheque\Contracts\Type;

use Folklore\Mediatheque\Contracts\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A type as 1.x code reads it, from the 1.x type factory.
 *
 * @deprecated Use Mediatheque::ingest(), which detects the type. Removed in 3.0.
 */
interface Type
{
    public function name(): string;

    /**
     * @return class-string<Model&Media>
     */
    public function model(): string;

    /**
     * A new media of this type, not saved: setOriginalFile() ingests its file.
     */
    public function newModel(): Media;

    /**
     * @return Builder<Model&Media>
     */
    public function newQuery(): Builder;

    public function pathIsType(string $path): bool;
}
