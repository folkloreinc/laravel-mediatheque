<?php

namespace Folklore\Mediatheque\Ingest;

use DateTimeInterface;
use Folklore\Mediatheque\Contracts\Models\Media;
use Folklore\Mediatheque\Contracts\Models\PipelineRun;
use Folklore\Mediatheque\Exceptions\InvalidPath;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Stringable;

/**
 * Builds storage paths from `file_path_format` and `run_path_format`
 * (docs/decisions/0012). Paths are recorded on the files, so consumers never
 * build them again.
 *
 * Placeholders:
 *
 * - `{media.<attribute>}`: an attribute of the media's model, such as
 *   `{media.uid}` or an application's `{media.tenant_id}`;
 * - `{type}`, `{handle}`, `{extension}`;
 * - `{index}`: `-2` for the file at index 2 of a group, empty for a single
 *   file;
 * - `{run}`: the run's key, in `run_path_format`;
 * - `{date(Y/m)}`: the media's creation date (now, before it is saved), in a
 *   PHP date format.
 *
 * A placeholder that names a missing or empty attribute throws, and so does
 * a value that would leave its directory.
 */
final class PathFormatter
{
    public function __construct(
        private readonly MediathequeConfig $config,
    ) {}

    /**
     * The path of a media's original.
     */
    public function original(Media $media, string $extension): string
    {
        return $this->format($this->config->filePathFormat(), $media, [
            'handle' => 'original',
            'index' => null,
            'extension' => $extension,
        ]);
    }

    /**
     * The prefix of a run's files: every driver writes under it.
     */
    public function runPrefix(Media $media, string $run): string
    {
        return $this->format($this->config->runPathFormat(), $media, ['run' => $run]);
    }

    /**
     * The path of a file the `ffmpeg` driver writes in a run: under the run's
     * prefix, named like the last segment of `file_path_format`.
     */
    public function runFile(PipelineRun $run, string $handle, ?int $index, string $extension): string
    {
        $path = $this->format($this->config->filePathFormat(), $run->media(), [
            'handle' => $handle,
            'index' => $index,
            'extension' => $extension,
        ]);

        return $run->pathPrefix().'/'.basename($path);
    }

    /**
     * @param  array{handle?: string, index?: int|null, extension?: string, run?: string}  $values
     */
    private function format(string $format, Media $media, array $values): string
    {
        $path = (string) preg_replace_callback(
            '/\{([a-z_]+)(?:\.([A-Za-z0-9_]+)|\(([^)]*)\))?\}/',
            fn (array $matches) => $this->value($media, $values, $matches[1], $matches[2] ?? '', $matches[3] ?? '', $format),
            $format,
        );

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidPath(sprintf('The format "%s" gives the path "%s", which has an empty or relative segment.', $format, $path));
            }
        }

        return $path;
    }

    /**
     * @param  array{handle?: string, index?: int|null, extension?: string, run?: string}  $values
     */
    private function value(Media $media, array $values, string $name, string $attribute, string $argument, string $format): string
    {
        return match ($name) {
            'media' => self::segment($this->attribute($media, $attribute), $format, '{media.'.$attribute.'}'),
            'type' => self::segment($media->type(), $format, '{type}'),
            'date' => $this->date($media, $argument === '' ? 'Y/m' : $argument),
            'index' => array_key_exists('index', $values)
                ? ($values['index'] !== null ? '-'.$values['index'] : '')
                : throw new InvalidPath(sprintf('The placeholder {index} can\'t be used in "%s".', $format)),
            'handle', 'extension', 'run' => self::segment(
                $values[$name] ?? throw new InvalidPath(sprintf('The placeholder {%s} can\'t be used in "%s".', $name, $format)),
                $format,
                '{'.$name.'}',
            ),
            default => throw new InvalidPath(sprintf('The placeholder {%s} in "%s" is unknown.', $name, $format)),
        };
    }

    private function attribute(Media $media, string $attribute): mixed
    {
        if ($attribute === 'uid') {
            return $media->uid();
        }

        if (! $media instanceof Model) {
            throw new InvalidPath(sprintf('The placeholder {media.%s} needs an Eloquent media model.', $attribute));
        }

        return $media->getAttributes()[$attribute] ?? null;
    }

    private function date(Media $media, string $format): string
    {
        $column = $media instanceof Model ? $media->getCreatedAtColumn() : null;
        $created = $media instanceof Model && $column !== null ? $media->getAttribute($column) : null;
        $date = $created instanceof DateTimeInterface ? $created : Date::now();

        return $date->format($format);
    }

    /**
     * A value that stays in its segment of the path.
     */
    private static function segment(mixed $value, string $format, string $placeholder): string
    {
        if (is_int($value) || $value instanceof Stringable) {
            $value = (string) $value;
        }

        if (! is_string($value) || $value === '') {
            throw new InvalidPath(sprintf('The placeholder %s in "%s" has no value: set it before the path is built.', $placeholder, $format));
        }

        if (str_contains($value, '/') || str_contains($value, '\\') || $value === '.' || $value === '..' || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidPath(sprintf('The value of %s in "%s" would leave its directory.', $placeholder, $format));
        }

        return $value;
    }
}
