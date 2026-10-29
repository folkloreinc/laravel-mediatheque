<?php

namespace Folklore\Mediatheque\Compat;

use Folklore\Mediatheque\Exceptions\InvalidPipeline;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Upgrade\LegacyJobs;
use Illuminate\Support\Arr;

/**
 * Reads a 1.x `config/mediatheque.php` in the 2.0 shape (docs/upgrade.md).
 *
 * Values equal to the 1.x defaults are left out: the 2.0 defaults replace
 * them. The others are translated where 2.0 has the same concept:
 *
 * - `sources` become Laravel disks of the same name, so files keep the
 *   `source` they were stored with; a disk the application defines already
 *   is left as it is;
 * - the `mimes` of a type become a list of patterns, and its `pipeline`
 *   stays;
 * - the `jobs` of a pipeline become renditions when the `ffmpeg`
 *   transcoder can produce them;
 * - `uploads.max_size` (kilobytes), `process_timeout`, `jobs` and the
 *   ffmpeg binaries move to their 2.0 keys.
 *
 * What 2.0 doesn't have (routes, metadata readers, services, a 1.x
 * `file_path_format`) is dropped, with a notice. Keys of the 2.0 shape, and
 * keys the package doesn't know, are kept as they are.
 *
 * Given the keys whose 1.x value is an `env()` call (LegacyConfigFile), the
 * 2.0 keys they become keep reading their variable, even with the value of
 * a 1.x default: another environment may set it.
 */
final class LegacyConfig
{
    /**
     * Keys that only the 1.x shape has.
     */
    private const array KEYS_1X = ['sources', 'metadatas', 'routes', 'services', 'uploads', 'process_timeout', 'debug'];

    /**
     * Mimes that say nothing about the content: 2.0 detects the type from
     * the content and refuses what matches no type.
     */
    private const array GENERIC_MIMES = ['application/octet-stream', 'inode/x-empty'];

    /**
     * Whether a config has the 1.x shape.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function isLegacy(array $config): bool
    {
        if (array_intersect(array_keys($config), self::KEYS_1X) !== []) {
            return true;
        }

        foreach (is_array($config['types'] ?? null) ? $config['types'] : [] as $type) {
            if (is_array($type) && is_array($type['mimes'] ?? null) && ! array_is_list($type['mimes'])) {
                return true;
            }
        }

        foreach (is_array($config['pipelines'] ?? null) ? $config['pipelines'] : [] as $pipeline) {
            if (is_array($pipeline) && array_key_exists('jobs', $pipeline)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $config
     * @param  array<array-key, mixed>|null  $disks  The disks of config/filesystems.php
     * @param  array<string, string>  $env  The environment variable of each 1.x key whose value is an env() call, by dot path (LegacyConfigFile): the 2.0 key it becomes keeps reading it
     */
    public static function convert(array $config, ?array $disks = null, array $env = []): ConfigConversion
    {
        $disks ??= self::configuredDisks();
        $defaults = V1Defaults::config();
        $converted = [];
        $types = [];
        $pipelines = [];
        $jobs = [];
        $binaries = [];
        $newDisks = [];
        $notices = [];
        $conflicts = [];
        $variables = [];

        foreach ($config as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            switch ($key) {
                case 'sources':
                    foreach (is_array($value) ? $value : [] as $name => $source) {
                        if (! is_string($name) || ! is_array($source)) {
                            continue;
                        }

                        $disk = self::disk($source, $disks);

                        if (isset($disks[$name])) {
                            // Laravel always defines `public`, which a 1.x source of the
                            // same name may not be: its files would resolve elsewhere
                            $existing = is_array($disks[$name]) ? $disks[$name] : [];
                            if ($disk !== null && ! self::sameDisk($disk, $existing)) {
                                $conflicts[] = sprintf(
                                    'The 1.x source "%s" (%s) is not the disk "%s" of config/filesystems.php (%s): the files stored on the source would resolve to the disk, elsewhere. Define the disk "%s" as the source was.',
                                    $name, self::describe($disk), $name, self::describe($existing), $name,
                                );
                            }

                            continue;
                        }

                        if ($disk === null) {
                            $notices[] = sprintf('The 1.x source "%s" can\'t be made a disk: define a disk "%s" in config/filesystems.php for the files stored on it.', $name, $name);
                        } else {
                            $newDisks[$name] = $disk;
                        }
                    }
                    break;

                case 'file_path_format':
                    if ($value === $defaults[$key]) {
                        break;
                    }
                    if (is_string($value) && str_contains($value, '{media.uid}') && str_contains($value, '{handle}') && str_contains($value, '{index}')) {
                        $converted[$key] = $value;
                    } else {
                        $notices[] = 'The 1.x `file_path_format` is dropped: a 2.0 path has {media.uid}, {handle} and {index}. Files stored already keep their paths.';
                    }
                    break;

                case 'types':
                    foreach (is_array($value) ? $value : [] as $name => $type) {
                        $default = Arr::get($defaults, 'types.'.$name);
                        if (! is_string($name) || $type == $default) {
                            continue;
                        }

                        $type = self::type($name, $type, is_array($default) ? $default : null, $notices);
                        if ($type !== null) {
                            $types[$name] = $type;
                        }
                    }
                    break;

                case 'pipelines':
                    foreach (is_array($value) ? $value : [] as $name => $pipeline) {
                        if (! is_string($name) || $pipeline == Arr::get($defaults, 'pipelines.'.$name)) {
                            continue;
                        }

                        $pipeline = self::pipeline($name, $pipeline, $notices);
                        if ($pipeline !== null) {
                            $pipelines[$name] = $pipeline;
                        }
                    }
                    break;

                case 'uploads':
                    $maxSize = is_array($value) ? ($value['max_size'] ?? null) : null;
                    if (is_numeric($maxSize) && (int) $maxSize > 0) {
                        $converted['ingest'] = ['max_size' => (int) $maxSize * 1024];
                    }
                    break;

                case 'process_timeout':
                    if (is_numeric($value) && (int) $value > 0 && ((int) $value !== $defaults[$key] || isset($env[$key]))) {
                        $jobs['timeout'] ??= (int) $value;
                        if (isset($env[$key])) {
                            $variables['jobs.timeout'] ??= $env[$key];
                        }
                    }
                    break;

                case 'jobs':
                    foreach (is_array($value) ? $value : [] as $option => $setting) {
                        if (! in_array($option, ['timeout', 'tries'], true)) {
                            // A key of the 2.0 shape: connection, queue
                            $jobs[$option] = $setting;
                        } elseif (is_numeric($setting) && (int) $setting > 0) {
                            $jobs[$option] = (int) $setting;
                            if (isset($env['jobs.'.$option])) {
                                $variables['jobs.'.$option] = $env['jobs.'.$option];
                            } else {
                                unset($variables['jobs.'.$option]);
                            }
                        } elseif (isset($env['jobs.'.$option])) {
                            // Read without a default, as the 1.x defaults do: the
                            // 2.0 default applies when the variable is not set
                            $jobs[$option] ??= self::jobsDefault($option);
                            $variables['jobs.'.$option] = $env['jobs.'.$option];
                        }
                    }
                    break;

                case 'services':
                    // `ffmpeg.binaries` has a dot: not a path for Arr::get()
                    $ffmpeg = is_array($value) && is_array($value['ffmpeg'] ?? null) ? $value['ffmpeg'] : [];
                    foreach (['ffmpeg', 'ffprobe'] as $binary) {
                        $path = $ffmpeg[$binary.'.binaries'] ?? null;
                        $variable = $env['services.ffmpeg.'.$binary.'.binaries'] ?? null;
                        if (is_string($path) && $path !== '' && ($path !== '/usr/local/bin/'.$binary || $variable !== null)) {
                            $binaries[$binary] = $path;
                            if ($variable !== null) {
                                $variables['binaries.'.$binary] = $variable;
                            }
                        }
                    }
                    if (Arr::get(is_array($value) ? $value : [], 'mediaConvert.role') !== null) {
                        $notices[] = 'MediaConvert is configured: the `mediaconvert` transcoder of 2.0 is not available yet, so its pipelines run on `ffmpeg`.';
                    }
                    break;

                case 'routes':
                    if (Arr::get(is_array($value) ? $value : [], 'map') !== null) {
                        $notices[] = '2.0 has no upload routes: take files in your own routes and call Mediatheque::ingest() (docs/upgrade.md).';
                    }
                    break;

                case 'metadatas':
                case 'debug':
                    break;

                default:
                    $converted[$key] = $value;
                    foreach ($env as $path => $variable) {
                        if ($path === $key || str_starts_with($path, $key.'.')) {
                            $variables[$path] = $variable;
                        }
                    }
            }
        }

        foreach ($types as $name => $type) {
            $pipeline = is_array($type) ? ($type['pipeline'] ?? false) : false;
            if (is_array($type) && is_string($pipeline) && ! isset($pipelines[$pipeline]) && ! self::hasDefaultPipeline($pipeline)) {
                $type['pipeline'] = null;
                $types[$name] = $type;
                $notices[] = sprintf('The type "%s" ran the 1.x pipeline "%s", which 2.0 can\'t run: it has no pipeline now.', $name, $pipeline);
            }
        }

        $converted = [
            ...$converted,
            ...array_filter(['types' => $types, 'pipelines' => $pipelines, 'binaries' => $binaries]),
        ];
        if ($jobs !== []) {
            $converted['jobs'] = $jobs;
        }

        return new ConfigConversion($converted, $newDisks, array_values(array_unique([...$conflicts, ...$notices])), $variables, $conflicts);
    }

    /**
     * The 2.0 default of `jobs.tries` or `jobs.timeout`.
     */
    private static function jobsDefault(string $option): int
    {
        $defaults = require dirname(__DIR__, 2).'/config/mediatheque.php';
        $default = is_array($defaults) && is_array($defaults['jobs'] ?? null) ? ($defaults['jobs'][$option] ?? null) : null;

        return is_int($default) ? $default : ($option === 'tries' ? 3 : 3600);
    }

    /**
     * A 2.0 type from a 1.x one: its mimes as patterns, and its pipeline.
     * Null when nothing differs from the 2.0 defaults.
     *
     * @param  array<array-key, mixed>|null  $default
     * @param  list<string>  $notices
     * @return array<array-key, mixed>|false|null
     */
    private static function type(string $name, mixed $type, ?array $default, array &$notices): array|false|null
    {
        if ($type === false) {
            return false;
        }

        if (! is_array($type)) {
            return null;
        }

        $mimes = $type['mimes'] ?? null;
        if (is_array($mimes) && array_is_list($mimes)) {
            // The 2.0 shape already
            return $type;
        }

        $converted = [];

        if (is_array($mimes) && $mimes != ($default['mimes'] ?? null)) {
            $patterns = [];
            foreach (array_keys($mimes) as $mime) {
                if (is_string($mime) && ! in_array(strtolower($mime), self::GENERIC_MIMES, true)) {
                    $patterns[] = strtolower($mime);
                } else {
                    $notices[] = sprintf('The mime "%s" of the type "%s" is dropped: 2.0 detects types from the content.', $mime, $name);
                }
            }
            if ($patterns !== []) {
                $converted['mimes'] = $patterns;
            }
        }

        if (array_key_exists('pipeline', $type) && $type['pipeline'] !== ($default['pipeline'] ?? null)) {
            $converted['pipeline'] = $type['pipeline'];
        }

        if ($default === null && ! isset($converted['mimes'])) {
            $notices[] = sprintf('The type "%s" has no mimes: it is left out.', $name);

            return null;
        }

        return $converted !== [] ? $converted : null;
    }

    /**
     * A 2.0 pipeline from a 1.x one: the renditions of its jobs that the
     * `ffmpeg` transcoder can produce. Null when none can be produced.
     *
     * @param  list<string>  $notices
     * @return array<array-key, mixed>|false|null
     */
    private static function pipeline(string $name, mixed $pipeline, array &$notices): array|false|null
    {
        if ($pipeline === false || $pipeline === null) {
            return $pipeline === false ? false : null;
        }

        if (! is_array($pipeline)) {
            $notices[] = sprintf('The pipeline "%s" is a class: 2.0 pipelines are renditions in the config. It is left out.', $name);

            return null;
        }

        if (! array_key_exists('jobs', $pipeline)) {
            return $pipeline;
        }

        $queue = is_string($pipeline['queue'] ?? null) ? $pipeline['queue'] : null;
        $fromFile = $pipeline['from_file'] ?? null;
        $renditions = [];

        foreach (is_array($pipeline['jobs']) ? $pipeline['jobs'] : [] as $job => $definition) {
            if (! is_string($job) || $definition === null) {
                continue;
            }

            $options = is_array($definition) ? $definition : ['job' => $definition];
            $options['queue'] ??= $queue;
            $options['from_file'] ??= $fromFile;
            $rendition = LegacyJobs::rendition($options);

            if ($rendition === null) {
                $notices[] = sprintf('The job "%s" of the pipeline "%s" (%s) has no 2.0 operation: write a custom operation of the ffmpeg transcoder (docs/upgrade.md).', $job, $name, LegacyJobs::jobClass($options) ?? 'no class');
            } elseif (! LegacyJobs::runsOnFfmpeg($rendition)) {
                $notices[] = sprintf('The job "%s" of the pipeline "%s" becomes the operation "%s", which the ffmpeg transcoder doesn\'t run: it is left out.', $job, $name, is_string($rendition['op']) ? $rendition['op'] : '');
            } else {
                $renditions[$job] = $rendition;
            }
        }

        if ($renditions === []) {
            $notices[] = sprintf('The pipeline "%s" has no rendition that 2.0 can produce: the 2.0 default applies, if there is one.', $name);

            return null;
        }

        try {
            PipelineDefinition::fromArray($name, ['renditions' => $renditions]);
        } catch (InvalidPipeline $exception) {
            $notices[] = sprintf('The pipeline "%s" can\'t be converted: %s', $name, $exception->getMessage());

            return null;
        }

        return ['renditions' => $renditions];
    }

    /**
     * The disk of a 1.x source: a local source is a local disk, a
     * `filesystem` source is its disk, under its path.
     *
     * @param  array<array-key, mixed>  $source
     * @param  array<array-key, mixed>  $disks
     * @return array<array-key, mixed>|null
     */
    private static function disk(array $source, array $disks): ?array
    {
        $url = is_string($source['url'] ?? null) && $source['url'] !== '' ? $source['url'] : null;
        $visibility = is_string($source['visibility'] ?? null) ? $source['visibility'] : 'public';

        if (($source['driver'] ?? null) === 'local' && is_string($source['path'] ?? null)) {
            return array_filter([
                'driver' => 'local',
                'root' => $source['path'],
                'url' => $url,
                'visibility' => $visibility,
                'throw' => false,
            ], fn (mixed $value) => $value !== null);
        }

        $base = is_string($source['disk'] ?? null) ? ($disks[$source['disk']] ?? null) : null;
        if (($source['driver'] ?? null) !== 'filesystem' || ! is_array($base)) {
            return null;
        }

        $disk = $base;
        $path = trim(is_string($source['path'] ?? null) ? $source['path'] : '', '/');
        if ($path !== '') {
            $root = is_string($base['root'] ?? null) ? $base['root'] : '';
            $disk['root'] = ($base['driver'] ?? null) === 'local' ? rtrim($root, '/').'/'.$path : trim($root.'/'.$path, '/');
        }
        if ($url !== null) {
            $disk['url'] = $url;
        }
        $disk['visibility'] = $visibility;

        return $disk;
    }

    /**
     * Whether a disk made from a 1.x source stores and serves files where
     * an existing disk does: the same driver, root and URL.
     *
     * @param  array<array-key, mixed>  $source
     * @param  array<array-key, mixed>  $disk
     */
    private static function sameDisk(array $source, array $disk): bool
    {
        $string = fn (array $values, string $key): ?string => is_string($values[$key] ?? null) && $values[$key] !== '' ? rtrim($values[$key], '/') : null;

        if ($string($source, 'driver') !== $string($disk, 'driver') || $string($source, 'root') !== $string($disk, 'root')) {
            return false;
        }

        $url = $string($source, 'url');
        $diskUrl = $string($disk, 'url');
        if ($url === null || $url === $diskUrl) {
            return true;
        }

        // A relative URL is the path of the disk's
        return $diskUrl !== null && (str_starts_with($url, '/') || str_starts_with($diskUrl, '/'))
            && rtrim((string) parse_url($url, PHP_URL_PATH), '/') === rtrim((string) parse_url($diskUrl, PHP_URL_PATH), '/');
    }

    /**
     * @param  array<array-key, mixed>  $disk
     */
    private static function describe(array $disk): string
    {
        $parts = [];
        foreach (['driver', 'root', 'url'] as $key) {
            if (is_string($disk[$key] ?? null) && $disk[$key] !== '') {
                $parts[] = $key.' '.$disk[$key];
            }
        }

        return $parts !== [] ? implode(', ', $parts) : 'no root';
    }

    private static function hasDefaultPipeline(string $name): bool
    {
        $defaults = require dirname(__DIR__, 2).'/config/mediatheque.php';

        return is_array($defaults) && is_array($defaults['pipelines'] ?? null) && is_array($defaults['pipelines'][$name] ?? null);
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function configuredDisks(): array
    {
        $disks = config('filesystems.disks', []);

        return is_array($disks) ? $disks : [];
    }
}
