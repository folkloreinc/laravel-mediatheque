<?php

namespace Folklore\Mediatheque\Services;

use Folklore\Mediatheque\Contracts\Services\PathFormatter as PathFormatterContract;
use Illuminate\Contracts\Support\Arrayable;

class PathFormatter implements PathFormatterContract
{
    /**
     * Get family name from a file
     *
     * @param  string  $path
     * @return string
     */
    public function formatPath($format, ...$params)
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
            function ($path, $method) {
                return $this->{$method}($path);
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

    protected function replaceDate($path)
    {
        return preg_replace_callback(
            '/\{\s*date\(([^\)]+)\)\s*\}/',
            function ($matches) {
                return date($matches[1]);
            },
            $path
        );
    }
}
