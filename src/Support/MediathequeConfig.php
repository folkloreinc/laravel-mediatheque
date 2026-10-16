<?php

namespace Folklore\Mediatheque\Support;

use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Folklore\Mediatheque\Exceptions\InvalidPipeline;
use Folklore\Mediatheque\Pipelines\PipelineDefinition;
use Folklore\Mediatheque\Pipelines\Rendition;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;

/**
 * Reads `config('mediatheque')`, already merged with the package's defaults
 * (ConfigMerger), and validates each value as it reads it, so a mistake shows
 * with the key that is wrong.
 *
 * It reads the repository on every call: tests and applications can change
 * the config at runtime.
 */
final class MediathequeConfig
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function tablePrefix(): string
    {
        $prefix = $this->config->get('mediatheque.table_prefix', '');

        return is_string($prefix) ? $prefix : throw InvalidConfig::at('table_prefix', 'expected a string');
    }

    /**
     * The model class set for a contract in `models`: an Eloquent model that
     * implements it.
     *
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return class-string<Model&T>
     */
    public function model(string $key, string $contract): string
    {
        $class = $this->config->get('mediatheque.models.'.$key);

        if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! is_a($class, $contract, true)) {
            throw InvalidConfig::at('models.'.$key, sprintf('expected an Eloquent model that implements %s', $contract));
        }

        return $class;
    }

    /**
     * The prefix of media uids (`med`).
     */
    public function mediaPrefix(): string
    {
        $prefix = $this->config->get('mediatheque.ids.media_prefix', 'med');

        return is_string($prefix) && preg_match('/^[a-z][a-z0-9]*$/', $prefix) === 1
            ? $prefix
            : throw InvalidConfig::at('ids.media_prefix', 'expected lowercase letters and digits, starting with a letter');
    }

    /**
     * The disk originals are stored on by default.
     */
    public function defaultSource(): string
    {
        return $this->disk('source', $this->config->get('mediatheque.source'));
    }

    /**
     * Check that a value names a disk of `config('filesystems.disks')`.
     */
    public function disk(string $key, mixed $name): string
    {
        if (! is_string($name) || $name === '') {
            throw InvalidConfig::at($key, 'expected the name of a disk of config/filesystems.php');
        }

        if (! is_array($this->config->get('filesystems.disks.'.$name))) {
            throw InvalidConfig::at($key, sprintf('"%s" is not a disk of config/filesystems.php; a source is a Laravel disk name', $name));
        }

        return $name;
    }

    public function defaultVisibility(): Visibility
    {
        $visibility = $this->config->get('mediatheque.ingest.visibility', Visibility::Public->value);

        return is_string($visibility) && Visibility::tryFrom($visibility) !== null
            ? Visibility::from($visibility)
            : throw InvalidConfig::at('ingest.visibility', 'expected "public" or "private"');
    }

    /**
     * The maximum size of an ingested file, in bytes: the type's, or the
     * default for every type. Null for no limit.
     */
    public function maxSize(?TypeDefinition $type = null): ?int
    {
        return $type->maxSize ?? self::size('ingest.max_size', $this->config->get('mediatheque.ingest.max_size'));
    }

    /**
     * The format of the paths of originals, and of the names of the files
     * the `ffmpeg` driver writes. It must contain `{media.uid}`, so each
     * media gets its own paths.
     */
    public function filePathFormat(): string
    {
        $format = $this->config->get('mediatheque.file_path_format');

        if (! is_string($format) || ! str_contains($format, '{media.uid}') || ! str_contains($format, '{handle}') || ! str_contains($format, '{index}')) {
            throw InvalidConfig::at('file_path_format', 'expected a path format with {media.uid}, {handle} and {index}, such as "{type}/{media.uid}/{handle}{index}.{extension}"');
        }

        return $format;
    }

    /**
     * The format of the prefix of a run's files. It must contain `{run}`, so
     * a run never overwrites the files of another.
     */
    public function runPathFormat(): string
    {
        $format = $this->config->get('mediatheque.run_path_format');

        if (! is_string($format) || ! str_contains($format, '{run}')) {
            throw InvalidConfig::at('run_path_format', 'expected a path format with {run}, such as "{type}/{media.uid}/{run}"');
        }

        return $format;
    }

    /**
     * The disk that receives presigned multipart uploads.
     */
    public function uploadsDisk(): string
    {
        return $this->disk('uploads.disk', $this->config->get('mediatheque.uploads.disk'));
    }

    /**
     * The directory of uploads on their disk.
     */
    public function uploadsPath(): string
    {
        $path = $this->config->get('mediatheque.uploads.path', 'mediatheque-uploads');

        return is_string($path) && $path !== '' && ! str_contains($path, '..') ? trim($path, '/') : throw InvalidConfig::at('uploads.path', 'expected a directory');
    }

    /**
     * Seconds an upload can take before it expires.
     */
    public function uploadsExpireAfter(): int
    {
        return $this->positiveInt('uploads.expires_after', 86400);
    }

    /**
     * Seconds a signed part URL lives.
     */
    public function uploadsUrlTtl(): int
    {
        return $this->positiveInt('uploads.url_ttl', 3600);
    }

    /**
     * The largest file any type accepts, in bytes: the limit of an upload
     * before its type is known. Null for no limit.
     */
    public function largestSize(): ?int
    {
        $sizes = [];

        foreach ($this->types() as $type) {
            $size = $this->maxSize($type);
            if ($size === null) {
                return null;
            }
            $sizes[] = $size;
        }

        return $sizes === [] ? null : max($sizes);
    }

    /**
     * The command of a binary: `ffmpeg`, `ffprobe`.
     */
    public function binary(string $name): string
    {
        $binary = $this->config->get('mediatheque.binaries.'.$name);

        return is_string($binary) && $binary !== '' ? $binary : throw InvalidConfig::at('binaries.'.$name, 'expected a command or an absolute path');
    }

    /**
     * Seconds the probe of one file can take.
     */
    public function probeTimeout(): int
    {
        return $this->positiveInt('probe.timeout', 120);
    }

    /**
     * The queue connection of the package's jobs; null for the default.
     */
    public function jobsConnection(): ?string
    {
        return $this->optionalString('jobs.connection');
    }

    /**
     * The queue of the package's jobs; null for the connection's default.
     */
    public function jobsQueue(): ?string
    {
        return $this->optionalString('jobs.queue');
    }

    public function jobsTries(): int
    {
        return $this->positiveInt('jobs.tries', 3);
    }

    /**
     * Seconds a job, and each process it runs, can take.
     */
    public function jobsTimeout(): int
    {
        return $this->positiveInt('jobs.timeout', 3600);
    }

    /**
     * Seconds a temporary URL lives when url() returns one.
     */
    public function temporaryTtl(): int
    {
        $ttl = $this->config->get('mediatheque.urls.temporary_ttl', 3600);

        return is_int($ttl) && $ttl > 0 ? $ttl : throw InvalidConfig::at('urls.temporary_ttl', 'expected a number of seconds');
    }

    /**
     * The types, in the order of the config: detection picks the first that
     * accepts a mime.
     *
     * @return array<string, TypeDefinition>
     */
    public function types(): array
    {
        $types = [];

        foreach ($this->section('types') as $name => $definition) {
            if ($definition !== false) {
                $types[$name] = $this->readType($name, $definition);
            }
        }

        return $types;
    }

    public function type(string $name): TypeDefinition
    {
        return $this->types()[$name] ?? throw InvalidConfig::at('types', sprintf('the type "%s" is not configured', $name));
    }

    /**
     * The type of a mime sniffed from the content: the first type that
     * accepts it, or null.
     */
    public function detectType(string $mime): ?TypeDefinition
    {
        foreach ($this->types() as $type) {
            if ($type->accepts($mime)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * @return array<string, PipelineDefinition>
     */
    public function pipelines(): array
    {
        $pipelines = [];

        foreach ($this->section('pipelines') as $name => $definition) {
            if ($definition !== false) {
                $pipelines[$name] = $this->readPipeline($name, $definition);
            }
        }

        return $pipelines;
    }

    public function pipeline(string $name): PipelineDefinition
    {
        $definition = $this->section('pipelines')[$name] ?? false;

        if ($definition === false) {
            throw InvalidConfig::at('pipelines', sprintf('the pipeline "%s" is not configured', $name));
        }

        return $this->readPipeline($name, $definition);
    }

    /**
     * The pipeline a type runs by default, or null when it has none.
     */
    public function pipelineOfType(string $type): ?PipelineDefinition
    {
        $pipeline = $this->type($type)->pipeline;

        return $pipeline !== null ? $this->pipeline($pipeline) : null;
    }

    /**
     * The name of the rendition a media of this type is played from, or null
     * when its pipeline has none.
     */
    public function mainRenditionOfType(string $type): ?string
    {
        $types = $this->types();

        if (! isset($types[$type]) || $types[$type]->pipeline === null) {
            return null;
        }

        return $this->pipeline($types[$type]->pipeline)->mainRendition()?->name;
    }

    /**
     * Read every type and pipeline, so a mistake throws now.
     */
    public function validate(): void
    {
        $this->tablePrefix();
        $this->mediaPrefix();
        $this->defaultSource();
        $this->defaultVisibility();
        $this->filePathFormat();
        $this->runPathFormat();
        $this->maxSize();
        $this->temporaryTtl();
        $this->binary('ffmpeg');
        $this->binary('ffprobe');
        $this->probeTimeout();
        $this->jobsConnection();
        $this->jobsQueue();
        $this->jobsTries();
        $this->jobsTimeout();
        $this->types();
        $this->pipelines();
    }

    /**
     * @return array<string, mixed>
     */
    private function section(string $key): array
    {
        $section = $this->config->get('mediatheque.'.$key, []);

        if (! is_array($section)) {
            throw InvalidConfig::at($key, 'expected an array keyed by name');
        }

        $keyed = [];
        foreach ($section as $name => $value) {
            if (! is_string($name)) {
                throw InvalidConfig::at($key, 'expected an array keyed by name');
            }
            $keyed[$name] = $value;
        }

        return $keyed;
    }

    private function readType(string $name, mixed $definition): TypeDefinition
    {
        $key = 'types.'.$name;

        if (! is_array($definition)) {
            throw InvalidConfig::at($key, 'expected an array, or false to remove the type');
        }

        if (! Rendition::isValidHandle($name)) {
            throw InvalidConfig::at($key, 'a type name starts with a letter, then letters, digits, "_" and "-"');
        }

        $mimes = $definition['mimes'] ?? null;
        if (! is_array($mimes) || $mimes === [] || ! array_is_list($mimes)) {
            throw InvalidConfig::at($key.'.mimes', 'expected a list of mime patterns, such as "video/*"');
        }
        foreach ($mimes as $mime) {
            if (! is_string($mime) || preg_match('#^[a-z0-9*][a-z0-9.+*-]*/[a-z0-9*][a-z0-9.+*-]*$#', strtolower($mime)) !== 1) {
                throw InvalidConfig::at($key.'.mimes', sprintf('"%s" is not a mime pattern, such as "video/*"', is_string($mime) ? $mime : get_debug_type($mime)));
            }
        }

        $pipelines = $this->section('pipelines');
        if (array_key_exists('pipeline', $definition)) {
            $pipeline = $definition['pipeline'];
            if ($pipeline !== null && (! is_string($pipeline) || ($pipelines[$pipeline] ?? false) === false)) {
                throw InvalidConfig::at($key.'.pipeline', sprintf(
                    'expected the name of a configured pipeline, or null for none; "%s" is not configured',
                    is_string($pipeline) ? $pipeline : get_debug_type($pipeline),
                ));
            }
        } else {
            $pipeline = ($pipelines[$name] ?? false) !== false ? $name : null;
        }

        return new TypeDefinition(
            name: $name,
            mimes: array_map(fn (string $mime) => strtolower($mime), $mimes),
            pipeline: $pipeline,
            maxSize: self::size($key.'.max_size', $definition['max_size'] ?? null),
        );
    }

    private function readPipeline(string $name, mixed $definition): PipelineDefinition
    {
        $key = 'pipelines.'.$name;

        if (! is_array($definition)) {
            throw InvalidConfig::at($key, 'expected an array, or false to remove the pipeline');
        }

        if ($name === PipelineDefinition::PROBE) {
            throw InvalidConfig::at($key, 'the pipeline name "probe" is reserved for pending probes');
        }

        try {
            $pipeline = PipelineDefinition::fromArray($name, $definition);
        } catch (InvalidPipeline $exception) {
            throw InvalidConfig::at($key, $exception->getMessage());
        }

        if ($pipeline->source !== null) {
            $this->disk($key.'.source', $pipeline->source);
        }

        return $pipeline;
    }

    private function positiveInt(string $key, int $default): int
    {
        $value = $this->config->get('mediatheque.'.$key, $default);

        return is_int($value) && $value > 0 ? $value : throw InvalidConfig::at($key, 'expected a positive integer');
    }

    private function optionalString(string $key): ?string
    {
        $value = $this->config->get('mediatheque.'.$key);

        return $value === null || $value === '' ? null : (is_string($value) ? $value : throw InvalidConfig::at($key, 'expected a name, or null for the default'));
    }

    /**
     * A size in bytes, from an integer or a string with a unit: `500M`,
     * `2G`.
     */
    private static function size(string $key, mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\s*(\d+)\s*([KMGT]?)B?\s*$/i', $value, $matches) === 1) {
            $exponent = match (strtoupper($matches[2])) {
                'K' => 1,
                'M' => 2,
                'G' => 3,
                'T' => 4,
                default => 0,
            };
            $size = (int) $matches[1] * 1024 ** $exponent;

            if ($size > 0) {
                return $size;
            }
        }

        throw InvalidConfig::at($key, 'expected a size in bytes, or a string such as "500M" or "2G"');
    }
}
