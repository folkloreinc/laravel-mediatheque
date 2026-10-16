<?php

namespace Folklore\Mediatheque\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A JSON object as an array, which keeps floats as floats: a duration of
 * `12.0` is read back as `12.0`, not `12`.
 *
 * @implements CastsAttributes<array<string, mixed>|null, array<array-key, mixed>|null>
 */
class Json implements CastsAttributes
{
    private const int FLAGS = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : null;
        $array = [];

        foreach (is_array($decoded) ? $decoded : [] as $item => $content) {
            $array[(string) $item] = $content;
        }

        return $array;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : json_encode($value, self::FLAGS);
    }
}
