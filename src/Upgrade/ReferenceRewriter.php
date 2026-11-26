<?php

namespace Folklore\Mediatheque\Upgrade;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Rewrites the references to media that applications stored by id in their
 * own JSON columns (`media://12`, `medias://12`) to `media://<uid>`, once
 * the media have their uid (#176).
 *
 * The columns are read as raw JSON, with no model: every string value that
 * is exactly `<key>://<digits>` is a reference, at any depth. A uid is never
 * digits, so running it again changes nothing, and an id that matches no
 * media is left as it is.
 */
final class ReferenceRewriter
{
    public const array KEYS = ['media', 'medias'];

    /**
     * The uids by media id, or `true` for a check before they are backfilled.
     *
     * @var array<int, string|true>|null
     */
    private ?array $media = null;

    /**
     * @param  list<string>  $keys  The schemes of the references
     * @param  int<1, max>  $batch
     */
    public function __construct(
        private readonly LegacyTables $tables,
        private readonly array $keys = self::KEYS,
        private readonly int $batch = 500,
    ) {}

    /**
     * The table and column of a `table.column` option.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parse(string $reference): ?array
    {
        $parts = explode('.', $reference);

        return count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '' ? [$parts[0], $parts[1]] : null;
    }

    /**
     * Why a column can't be rewritten, or null: the rows are read and written
     * by their `id`.
     */
    public function invalid(string $table, string $column): ?string
    {
        $schema = Schema::connection($this->tables->db->getName());

        return match (true) {
            ! $schema->hasTable($table) => sprintf('There is no table %s.', $table),
            ! $schema->hasColumn($table, $column) => sprintf('The table %s has no column %s.', $table, $column),
            ! $schema->hasColumn($table, 'id') => sprintf('The table %s has no id column: its rows can\'t be rewritten one at a time.', $table),
            default => null,
        };
    }

    /**
     * Count the references of a column, and write nothing. Before the
     * conversion, a reference counts when its media exists.
     */
    public function inspect(string $table, string $column): ReferenceCount
    {
        return $this->walk($table, $column, false);
    }

    /**
     * Rewrite the references of a column whose media has a uid, in batches,
     * one transaction each.
     */
    public function rewrite(string $table, string $column): ReferenceCount
    {
        return $this->walk($table, $column, true);
    }

    private function walk(string $table, string $column, bool $write): ReferenceCount
    {
        $media = $this->media($write);
        $pattern = '/^(?:'.implode('|', array_map(fn (string $key) => preg_quote($key, '/'), $this->keys)).'):\/\/(\d+)$/';
        $db = $this->tables->db;
        $rows = 0;
        $references = 0;
        $missing = [];

        $db->table($table)->select(['id', $column])->chunkById($this->batch, function (Collection $chunk) use ($db, $table, $column, $write, $media, $pattern, &$rows, &$references, &$missing) {
            $updates = [];

            foreach ($chunk as $row) {
                $raw = Row::string($row, $column);
                $value = $raw !== null && $this->mayHaveReferences($raw) ? json_decode($raw) : null;
                if ($value === null) {
                    continue;
                }

                $count = 0;
                $value = $this->rewriteValue($value, $pattern, $media, $count, $missing);
                if ($count === 0) {
                    continue;
                }

                $rows++;
                $references += $count;
                $updates[(int) Row::int($row, 'id')] = json_encode($value);
            }

            if ($write && $updates !== []) {
                $db->transaction(function () use ($db, $table, $column, $updates) {
                    foreach ($updates as $id => $json) {
                        $db->table($table)->where('id', $id)->update([$column => $json]);
                    }
                });
            }
        });

        $missing = array_values(array_unique($missing));
        sort($missing);

        return new ReferenceCount($rows, $references, $missing);
    }

    /**
     * Whether the raw JSON has a scheme: its slashes may be escaped (`media:\/\/12`).
     */
    private function mayHaveReferences(string $raw): bool
    {
        foreach ($this->keys as $key) {
            if (str_contains($raw, $key.':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string|true>  $media
     * @param  list<int>  $missing
     */
    private function rewriteValue(mixed $value, string $pattern, array $media, int &$count, array &$missing): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->rewriteValue($item, $pattern, $media, $count, $missing);
            }

            return $value;
        }

        // Objects stay objects: an empty one is written back as {}, not []
        if ($value instanceof stdClass) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = $this->rewriteValue($item, $pattern, $media, $count, $missing);
            }

            return $value;
        }

        if (! is_string($value) || preg_match($pattern, $value, $matches) !== 1) {
            return $value;
        }

        $id = (int) $matches[1];
        $uid = $media[$id] ?? null;
        if ($uid === null) {
            $missing[] = $id;

            return $value;
        }

        $count++;

        return is_string($uid) ? 'media://'.$uid : $value;
    }

    /**
     * Deleted media count: a reference to one still resolves.
     *
     * @return array<int, string|true>
     */
    private function media(bool $write): array
    {
        if ($this->media !== null) {
            return $this->media;
        }

        $media = [];
        if ($write) {
            foreach ($this->tables->query('medias')->whereNotNull('uid')->get(['id', 'uid']) as $row) {
                $media[(int) Row::int($row, 'id')] = (string) Row::string($row, 'uid');
            }
        } else {
            foreach ($this->tables->query('medias')->pluck('id') as $id) {
                $media[(int) (is_numeric($id) ? $id : 0)] = true;
            }
        }

        return $this->media = $media;
    }
}
