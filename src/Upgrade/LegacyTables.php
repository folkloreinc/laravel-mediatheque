<?php

namespace Folklore\Mediatheque\Upgrade;

use Folklore\Mediatheque\Support\Models;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The tables of a database upgraded from 1.x, with the configured prefix,
 * and the morph types its rows use for media and files.
 *
 * 1.x attached metadata, files and pipelines to media and files through
 * morphs. The type in those rows is the class of the 1.x model, or its alias
 * in the morph map, and it may not be the class the application configures
 * now: the default 1.x classes, and the types given to the command, count
 * too.
 */
final readonly class LegacyTables
{
    public const string MEDIA_CLASS = 'Folklore\\Mediatheque\\Models\\Media';

    public const string FILE_CLASS = 'Folklore\\Mediatheque\\Models\\File';

    /**
     * @param  list<string>  $mediaTypes
     * @param  list<string>  $fileTypes
     */
    public function __construct(
        public Connection $db,
        public string $prefix,
        public string $mediaMorphClass,
        public array $mediaTypes,
        public array $fileTypes,
    ) {}

    /**
     * @param  list<string>  $mediaTypes  Other morph types of media in 1.x rows
     * @param  list<string>  $fileTypes  Other morph types of files in 1.x rows
     */
    public static function make(array $mediaTypes = [], array $fileTypes = []): self
    {
        $mediaClass = Models::media();
        $media = new $mediaClass;
        $fileClass = Models::file();
        $file = new $fileClass;
        $connection = $media->getConnection();

        return new self(
            $connection,
            Models::table(''),
            $media->getMorphClass(),
            self::unique([$media->getMorphClass(), $mediaClass, self::MEDIA_CLASS, ...$mediaTypes]),
            self::unique([$file->getMorphClass(), $fileClass, self::FILE_CLASS, ...$fileTypes]),
        );
    }

    /**
     * The default source: the disk that 1.x stored a file on when its row
     * has no `source`. It is `config('mediatheque.source')`, which the 1.x
     * config keeps, and the 2.0 config after `--config`.
     */
    public function defaultSource(): string
    {
        $source = config('mediatheque.source');

        return is_string($source) ? $source : '';
    }

    public function table(string $name): string
    {
        return $this->prefix.$name;
    }

    public function query(string $name, ?string $alias = null): Builder
    {
        return $this->db->table($this->table($name).($alias !== null ? ' as '.$alias : ''));
    }

    public function hasTable(string $name): bool
    {
        return Schema::connection($this->db->getName())->hasTable($this->table($name));
    }

    public function hasColumn(string $name, string $column): bool
    {
        return Schema::connection($this->db->getName())->hasColumn($this->table($name), $column);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function unique(array $values): array
    {
        return array_values(array_unique(array_filter($values, fn (string $value) => $value !== '')));
    }
}
