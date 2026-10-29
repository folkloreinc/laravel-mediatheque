<?php

namespace Folklore\Mediatheque\Upgrade;

/**
 * Reads a column of a row of the query builder. Drivers return the same
 * column as different types: an integer or a string on MySQL, a boolean or
 * an integer for 1.x booleans.
 */
final class Row
{
    public static function int(object $row, string $column): ?int
    {
        $value = $row->{$column} ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    public static function float(object $row, string $column): ?float
    {
        $value = $row->{$column} ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    public static function string(object $row, string $column): ?string
    {
        $value = $row->{$column} ?? null;

        return is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : null);
    }

    public static function bool(object $row, string $column): bool
    {
        return (bool) ($row->{$column} ?? false);
    }
}
