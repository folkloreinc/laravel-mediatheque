<?php

namespace Folklore\Mediatheque\Upgrade;

use Folklore\Mediatheque\Metadata\MetadataKey;

/**
 * The rows of the 1.x `metadatas` table, an entity-attribute-value table: one
 * row per name, with its value in the column of its type (`value_integer`,
 * `value_json`…). 2.0 keeps metadata as JSON, with the canonical keys
 * (docs/architecture.md#metadata-keys).
 */
final class LegacyMetadata
{
    /**
     * 1.x names whose canonical key is another name.
     */
    private const array KEYS = [
        'font_family_name' => 'font_names',
        'colors' => 'palette',
    ];

    /**
     * The value of a row, in the column its type names.
     */
    public static function value(object $row): mixed
    {
        $type = property_exists($row, 'type') && is_string($row->type) ? $row->type : 'string';
        $column = 'value_'.$type;
        $value = property_exists($row, $column) ? $row->{$column} : null;

        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => is_numeric($value) ? (int) $value : null,
            'float' => is_numeric($value) ? (float) $value : null,
            'boolean' => (bool) $value,
            'json' => is_string($value) ? json_decode($value, true) : $value,
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    /**
     * The metadata of the rows of one model, with the canonical keys. A row
     * without a value is left out.
     *
     * @param  iterable<object>  $rows
     * @return array<string, mixed>
     */
    public static function toArray(iterable $rows): array
    {
        $metadata = [];

        foreach ($rows as $row) {
            $name = property_exists($row, 'name') && is_string($row->name) ? $row->name : null;
            $value = self::value($row);

            if ($name === null || $name === '' || $value === null) {
                continue;
            }

            $key = self::key($name);
            // 1.x read one family name; 2.0 lists every name of the font
            $metadata[$key] = $key === 'font_names' && is_string($value) ? [$value] : $value;
        }

        ksort($metadata);

        return $metadata;
    }

    /**
     * The canonical key of a 1.x name.
     */
    public static function key(string $name): string
    {
        return self::KEYS[$name] ?? MetadataKey::normalizeKey($name);
    }
}
