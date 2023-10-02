<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\PathFormatter as PathFormatterContract;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;

class PathFormatter implements PathFormatterContract
{
    /**
     * Get family name from a file
     *
     * @param  string  $path
     * @return string
     */
    public function formatPath(string $format, ...$params): string
    {
        $replaces = array_reduce(
            $params,
            function ($map, $param) {
                return array_merge(
                    $map,
                    $param instanceof Arrayable ? $param->toArray() : $param
                );
            },
            []
        );

        $path = ltrim($format, '/');
        $replaceKeys = array_map(function ($key) {
            return preg_quote($key, '/');
        }, array_keys($replaces));
        $path = preg_replace_callback(
            '/\{\s*(' . implode('|', $replaceKeys) . ')\s*\}/i',
            function ($matches) use ($replaces) {
                return $replaces[$matches[1]];
            },
            $path
        );

        $path = array_reduce(
            $this->getReplaceMethods(),
            function ($path, $method) use ($replaces) {
                return $this->{$method}($path, $replaces);
            },
            $path
        );

        return $path;
    }

    protected function getReplaceMethods()
    {
        $methods = get_class_methods($this);
        return array_filter($methods, function ($method) {
            return preg_match('/^replace(.*?)$/', $method) > 0;
        });
    }

    protected function replaceDate($path, $replaces = null)
    {
        return preg_replace_callback(
            '/\{\s*date\(([^\)]+)\)\s*\}/',
            function ($matches) {
                return date($matches[1]);
            },
            $path
        );
    }

    protected function replaceSlug($path, $replaces = null)
    {
        return preg_replace_callback(
            '/\{\s*slug\(([^\)]+)\)\s*\}/',
            function ($matches) use ($replaces) {
                $name = data_get($replaces, 'name');
                $withoutExt = preg_replace('/\.\w+$/', '', $name);
                return Str::slug($withoutExt);
            },
            $path
        );
    }
}
