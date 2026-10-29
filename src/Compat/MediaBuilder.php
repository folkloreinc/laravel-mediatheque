<?php

namespace Folklore\Mediatheque\Compat;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The query builder of the media model. It eager loads the 1.x relation
 * `files` (`with('files')`, `load('files')`, `with('medias.files')` from a
 * model that has media) as `mediaFiles`: 2.0's files() is a method of the
 * contract, not a relation. `$media->files` reads the loaded files.
 *
 * @deprecated The `files` relation name is removed in 3.0: load `mediaFiles`.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class MediaBuilder extends Builder
{
    private const string LEGACY = 'files';

    private const string RELATION = 'mediaFiles';

    /**
     * @param  array<array-key, mixed>  $relations
     * @return array<array-key, mixed>
     */
    protected function parseWithRelations(array $relations)
    {
        $mapped = [];

        foreach ($relations as $key => $value) {
            if (is_string($key)) {
                $mapped[self::relation($key)] = $value;
            } else {
                $mapped[$key] = is_string($value) ? self::relation($value) : $value;
            }
        }

        return parent::parseWithRelations($mapped);
    }

    private static function relation(string $name): string
    {
        $segments = explode('.', $name);
        // The constraints of a relation follow its name: `files:id,path`
        $first = explode(':', $segments[0], 2);

        if ($first[0] !== self::LEGACY) {
            return $name;
        }

        Deprecation::trigger('Eager loading the media relation "files" is deprecated: load "mediaFiles".');
        $first[0] = self::RELATION;
        $segments[0] = implode(':', $first);

        return implode('.', $segments);
    }
}
