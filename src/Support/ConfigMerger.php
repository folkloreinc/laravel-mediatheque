<?php

namespace Folklore\Mediatheque\Support;

/**
 * Merges the package's config with the application's, so a published config
 * only needs its overrides.
 *
 * - Associative arrays are merged key by key, at every level.
 * - Lists (`mimes`) replace the default list as a whole.
 * - A pipeline's `renditions` replace the default renditions as a whole: a
 *   pipeline's renditions are one definition.
 * - `false` for a type or a pipeline removes it.
 */
final class ConfigMerger
{
    /**
     * Paths whose value replaces the default instead of being merged, with
     * `*` for any key.
     */
    private const array REPLACED = [
        'pipelines.*.renditions',
    ];

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $overrides
     * @return array<array-key, mixed>
     */
    public static function merge(array $defaults, array $overrides): array
    {
        return self::mergeAt($defaults, $overrides, []);
    }

    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $overrides
     * @param  list<string>  $path
     * @return array<array-key, mixed>
     */
    private static function mergeAt(array $defaults, array $overrides, array $path): array
    {
        $merged = $defaults;

        foreach ($overrides as $key => $value) {
            $default = $defaults[$key] ?? null;
            $keyPath = [...$path, (string) $key];

            $merged[$key] = is_array($value) && is_array($default) && self::isMap($value) && self::isMap($default) && ! self::isReplaced($keyPath)
                ? self::mergeAt($default, $value, $keyPath)
                : $value;
        }

        return $merged;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function isMap(array $value): bool
    {
        return $value !== [] && ! array_is_list($value);
    }

    /**
     * @param  list<string>  $path
     */
    private static function isReplaced(array $path): bool
    {
        foreach (self::REPLACED as $pattern) {
            $segments = explode('.', $pattern);
            if (count($segments) !== count($path)) {
                continue;
            }

            $matches = true;
            foreach ($segments as $position => $segment) {
                if ($segment !== '*' && $segment !== $path[$position]) {
                    $matches = false;
                    break;
                }
            }

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
