<?php

namespace Folklore\Mediatheque\Compat;

/**
 * A 1.x config read in the 2.0 shape (LegacyConfig): the overrides of the
 * 2.0 defaults, the disks that stand for the 1.x sources, and what couldn't
 * be converted.
 */
final readonly class ConfigConversion
{
    /**
     * @param  array<string, mixed>  $config  The overrides, in the 2.0 shape
     * @param  array<string, array<array-key, mixed>>  $disks  Disks to define, by 1.x source name
     * @param  list<string>  $notices  What was dropped, and why
     * @param  array<string, string>  $env  The environment variables that keys of the config read, by dot path: their value is the variable's default
     * @param  list<string>  $conflicts  1.x sources that an existing disk of the same name doesn't store like they did: their files resolve elsewhere
     */
    public function __construct(
        public array $config,
        public array $disks,
        public array $notices,
        public array $env = [],
        public array $conflicts = [],
    ) {}

    /**
     * The config file, with the overrides that differ from the 2.0 defaults
     * only.
     */
    public function toPhp(): string
    {
        $defaults = require dirname(__DIR__, 2).'/config/mediatheque.php';
        $overrides = $this->withoutDefaults($this->config, is_array($defaults) ? $defaults : []);

        return <<<PHP
            <?php

            /*
            |--------------------------------------------------------------------------
            | Mediatheque
            |--------------------------------------------------------------------------
            |
            | Only this application's overrides: the package merges them with its
            | defaults, in vendor/folklore/laravel-mediatheque/config/mediatheque.php.
            | Written by `php artisan mediatheque:upgrade --config` from the 1.x file.
            |
            */

            return {$this->export($overrides, 0, '')};

            PHP;
    }

    /**
     * The disks to add to config/filesystems.php, as PHP.
     */
    public function disksToPhp(): string
    {
        $lines = [];
        foreach ($this->disks as $name => $disk) {
            $lines[] = '    '.var_export($name, true).' => '.$this->export($disk, 1, null).',';
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * The values of a config that its defaults don't have, and those read
     * from the environment. Lists, and values that replace the default as a
     * whole (ConfigMerger), are kept whole when they differ.
     *
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>  $defaults
     * @return array<array-key, mixed>
     */
    private function withoutDefaults(array $config, array $defaults, string $path = ''): array
    {
        $kept = [];

        foreach ($config as $key => $value) {
            $keyPath = ltrim($path.'.'.$key, '.');
            $default = $defaults[$key] ?? null;

            if (array_key_exists($key, $defaults) && $value == $default && ! $this->readsEnv($keyPath)) {
                continue;
            }

            // A pipeline's renditions replace the default ones as a whole
            if (is_array($value) && is_array($default) && self::isMap($value) && self::isMap($default) && ! str_ends_with($keyPath, '.renditions')) {
                $value = $this->withoutDefaults($value, $default, $keyPath);
                if ($value === []) {
                    continue;
                }
            }

            $kept[$key] = $value;
        }

        return $kept;
    }

    /**
     * Whether a key, or a key under it, reads an environment variable.
     */
    private function readsEnv(string $path): bool
    {
        foreach (array_keys($this->env) as $key) {
            if ($key === $path || str_starts_with($key, $path.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function isMap(array $value): bool
    {
        return $value !== [] && ! array_is_list($value);
    }

    /**
     * @param  string|null  $path  The config key of the value, to read the environment variables of its keys; null for none
     */
    private function export(mixed $value, int $depth, ?string $path): string
    {
        if ($path !== null && isset($this->env[$path])) {
            // The environment gives strings: a number is cast, as 2.0 reads numbers
            return sprintf('%senv(%s, %s)', is_int($value) ? '(int) ' : '', var_export($this->env[$path], true), $this->export($value, $depth, null));
        }

        if (is_string($value)) {
            foreach (['storage_path' => storage_path(), 'base_path' => base_path()] as $function => $root) {
                if ($root !== '' && str_starts_with($value, $root.'/')) {
                    return sprintf('%s(%s)', $function, var_export(substr($value, strlen($root) + 1), true));
                }
            }
        }

        if (! is_array($value)) {
            return $value === null ? 'null' : var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $isList = array_is_list($value);
        $lines = [];

        foreach ($value as $key => $item) {
            $itemPath = $path !== null ? ltrim($path.'.'.$key, '.') : null;
            $lines[] = $indent.($isList ? '' : var_export($key, true).' => ').$this->export($item, $depth + 1, $itemPath).',';
        }

        return '['.PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL.str_repeat('    ', $depth).']';
    }
}
