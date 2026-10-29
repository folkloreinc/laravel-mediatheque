<?php

namespace Folklore\Mediatheque\Upgrade;

use Folklore\Mediatheque\Contracts\Models\File;
use Folklore\Mediatheque\Enums\JobStatus;
use Folklore\Mediatheque\Enums\MediaStatus;
use Folklore\Mediatheque\Enums\PipelineStatus;
use Folklore\Mediatheque\Enums\ProbeKind;
use Folklore\Mediatheque\Enums\TranscoderErrorCode;
use Folklore\Mediatheque\Enums\Visibility;
use Folklore\Mediatheque\Models\PipelineRun;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Support\Uid;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use stdClass;

/**
 * Converts the rows of a 1.x database to 2.0, once the migration published
 * by `mediatheque:install --upgrade` added the columns (docs/upgrade.md).
 *
 * Each step converts the rows it hasn't converted yet, in batches, one
 * transaction per batch: an interrupted upgrade resumes where it stopped,
 * and running it again changes nothing. The 1.x columns are read, never
 * changed, and rows that 2.0 wrote since the migration are left as they are.
 */
final class Converter
{
    /**
     * The 2.0 columns the conversion writes, by table.
     */
    public const array COLUMNS = [
        'medias' => ['uid', 'status', 'visibility', 'metadata'],
        'files' => ['media_id', 'index', 'role', 'metadata', 'width', 'height', 'duration'],
        'pipelines' => ['uid', 'transcoder', 'output_source', 'path_prefix', 'visibility', 'status', 'attempts', 'error'],
        'pipelines_jobs' => ['status', 'attempts', 'error', 'queued_at'],
    ];

    /**
     * The foreign keys that existing rows could break: added once orphan
     * rows are removed. `files.media_id` stays nullable: a file that 1.x
     * attached to other models only has no media.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    private const array FOREIGN_KEYS = [
        ['files', 'media_id', 'medias'],
        ['pipelines_jobs', 'pipeline_id', 'pipelines'],
        ['medias_pivot', 'media_id', 'medias'],
    ];

    private const int MESSAGE_LENGTH = 2000;

    /**
     * @param  int<1, max>  $batch
     */
    public function __construct(
        private readonly LegacyTables $tables,
        private readonly MediathequeConfig $config,
        private readonly int $batch = 500,
    ) {}

    /**
     * The 2.0 columns the database doesn't have yet, as `table.column`: the
     * migration hasn't run.
     *
     * @return list<string>
     */
    public function missingColumns(): array
    {
        $missing = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! $this->tables->hasColumn($table, $column)) {
                    $missing[] = $this->tables->table($table).'.'.$column;
                }
            }
        }

        return $missing;
    }

    /**
     * Files: their media from `files_pivot`, their handle split into handle
     * and index, their role, and their metadata as JSON. A file that no media
     * attaches is left: it is removed with the orphans, or kept for the other
     * models that attach it.
     */
    public function convertFiles(): int
    {
        $t = $this->tables;
        $converted = 0;

        $t->query('files')->whereNull('media_id')->select(['id', 'handle'])->chunkById($this->batch, function (Collection $files) use ($t, &$converted) {
            $ids = $files->pluck('id')->all();

            $pivots = [];
            if ($t->hasTable('files_pivot')) {
                foreach ($t->query('files_pivot', 'fp')
                    ->join($t->table('medias').' as m', 'm.id', '=', 'fp.morphable_id')
                    ->whereIn('fp.morphable_type', $t->mediaTypes)
                    ->whereIn('fp.file_id', $ids)
                    ->orderBy('fp.id')
                    ->get(['fp.file_id', 'fp.morphable_id', 'fp.handle']) as $pivot) {
                    $pivots[(int) Row::int($pivot, 'file_id')] ??= $pivot;
                }
            }

            $metadata = $this->metadataOf($t->fileTypes, $ids);

            $t->db->transaction(function () use ($t, $files, $pivots, $metadata, &$converted) {
                foreach ($files as $file) {
                    $id = (int) Row::int($file, 'id');
                    $pivot = $pivots[$id] ?? null;
                    if ($pivot === null) {
                        continue;
                    }

                    $handle = LegacyHandle::parse(Row::string($file, 'handle') ?? Row::string($pivot, 'handle') ?? 'file');
                    $values = $metadata[$id] ?? [];
                    $columns = array_intersect_key($values, array_flip(['width', 'height', 'duration']));
                    $values = array_diff_key($values, $columns);

                    $t->query('files')->where('id', $id)->whereNull('media_id')->update([
                        'media_id' => Row::int($pivot, 'morphable_id'),
                        'handle' => $handle->handle,
                        'index' => $handle->index,
                        'role' => $handle->role(),
                        'metadata' => $values !== [] ? self::json($values) : null,
                        'width' => is_numeric($columns['width'] ?? null) ? (int) $columns['width'] : null,
                        'height' => is_numeric($columns['height'] ?? null) ? (int) $columns['height'] : null,
                        'duration' => is_numeric($columns['duration'] ?? null) ? (float) $columns['duration'] : null,
                    ]);
                    $converted++;
                }
            });
        });

        return $converted;
    }

    /**
     * Media: a uid, made at their creation date so it sorts with them, their
     * metadata as JSON with the canonical keys, `public`, and `ready`, or
     * `failed` when their last 1.x run failed. Converts the files first: a
     * media's metadata takes the mime, size and name of its original.
     */
    public function convertMedia(): int
    {
        $t = $this->tables;
        $prefix = $this->config->mediaPrefix();
        $converted = 0;

        $t->query('medias')->whereNull('uid')->select(['id', 'type', 'created_at'])->chunkById($this->batch, function (Collection $medias) use ($t, $prefix, &$converted) {
            $ids = $medias->pluck('id')->all();
            $metadata = $this->metadataOf($t->mediaTypes, $ids);
            $failed = $this->lastRunFailed($ids);
            $originals = $t->query('files')
                ->whereIn('media_id', $ids)
                ->where('handle', File::ORIGINAL)
                ->orderBy('id')
                ->get(['media_id', 'name', 'mime', 'size'])
                ->keyBy(fn (stdClass $file) => (int) Row::int($file, 'media_id'));

            $t->db->transaction(function () use ($t, $prefix, $medias, $metadata, $failed, $originals, &$converted) {
                foreach ($medias as $media) {
                    $id = (int) Row::int($media, 'id');
                    $original = $originals->get($id);
                    $size = $original !== null ? Row::int($original, 'size') : null;
                    $values = array_filter([
                        'kind' => (ProbeKind::tryFrom((string) Row::string($media, 'type')) ?? ProbeKind::Other)->value,
                        'filename' => $original !== null ? Row::string($original, 'name') : null,
                        'mime' => $original !== null ? Row::string($original, 'mime') : null,
                        'size' => $size !== null && $size > 0 ? $size : null,
                    ], fn (mixed $value) => $value !== null) + ($metadata[$id] ?? []);
                    ksort($values);

                    $created = Row::string($media, 'created_at');
                    $t->query('medias')->where('id', $id)->whereNull('uid')->update([
                        'uid' => Uid::generate($prefix, $created !== null ? Carbon::parse($created) : null),
                        'status' => ($failed[$id] ?? false) ? MediaStatus::Failed->value : MediaStatus::Ready->value,
                        'visibility' => Visibility::Public->value,
                        'metadata' => self::json($values),
                    ]);
                    $converted++;
                }
            });
        });

        return $converted;
    }

    /**
     * Runs and their jobs: a uid, the 2.0 statuses from the 1.x booleans, the
     * error from the 1.x exception, and the definition as a JSON snapshot of
     * the renditions they ran. A run on a media gets the media's current
     * morph type.
     *
     * A run that hadn't ended, and the jobs that hadn't, are `canceled`: the
     * 1.x queue jobs that would have ended them don't exist in 2.0.
     */
    public function convertRuns(): int
    {
        $t = $this->tables;
        $converted = 0;
        $columns = ['id', 'name', 'pipelinable_type', 'pipelinable_id', 'definition', 'started', 'ended', 'failed', 'failed_exception', 'started_at', 'ended_at', 'created_at'];

        $t->query('pipelines')->whereNull('uid')->select($columns)->chunkById($this->batch, function (Collection $runs) use ($t, &$converted) {
            $jobs = $t->query('pipelines_jobs')
                ->whereIn('pipeline_id', $runs->pluck('id')->all())
                ->orderBy('id')
                ->get(['id', 'pipeline_id', 'name', 'definition', 'started', 'ended', 'failed', 'failed_exception', 'started_at', 'created_at'])
                ->groupBy(fn (stdClass $job) => (int) Row::int($job, 'pipeline_id'));

            $mediaIds = $runs->whereIn('pipelinable_type', $t->mediaTypes)->pluck('pipelinable_id')->all();
            $originals = $t->query('files')
                ->whereIn('media_id', $mediaIds)
                ->where('handle', File::ORIGINAL)
                ->orderBy('id')
                ->get(['media_id', 'source', 'path'])
                ->keyBy(fn (stdClass $file) => (int) Row::int($file, 'media_id'));

            $t->db->transaction(function () use ($runs, $jobs, $originals, &$converted) {
                foreach ($runs as $run) {
                    $this->convertRun($run, $jobs->get((int) Row::int($run, 'id'), new Collection), $originals->get((int) Row::int($run, 'pipelinable_id')));
                    $converted++;
                }
            });
        });

        return $converted;
    }

    /**
     * @param  Collection<int, stdClass>  $jobs
     */
    private function convertRun(stdClass $run, Collection $jobs, ?stdClass $original): void
    {
        $t = $this->tables;
        $definition = LegacyPipelineDefinition::parse(Row::string($run, 'definition'));
        $type = (string) Row::string($run, 'pipelinable_type');

        $renditions = [];
        foreach ($definition->jobs ?? [] as $name => $job) {
            $renditions[$name] = LegacyJobs::snapshot($job);
        }
        foreach ($jobs as $job) {
            $name = Row::string($job, 'name');
            if ($name !== null && ! isset($renditions[$name])) {
                $renditions[$name] = LegacyJobs::snapshot(json_decode((string) Row::string($job, 'definition'), true));
            }
        }

        $status = match (true) {
            Row::bool($run, 'failed') => PipelineStatus::Failed,
            Row::bool($run, 'ended') => PipelineStatus::Completed,
            default => PipelineStatus::Canceled,
        };

        $created = Row::string($run, 'created_at');
        $path = $original !== null ? Row::string($original, 'path') : null;

        $t->query('pipelines')->where('id', Row::int($run, 'id'))->whereNull('uid')->update([
            'uid' => Uid::generate(PipelineRun::UID_PREFIX, $created !== null ? Carbon::parse($created) : null),
            'name' => Row::string($run, 'name') ?? $definition->name ?? 'v1',
            'pipelinable_type' => in_array($type, $t->mediaTypes, true) ? $t->mediaMorphClass : $type,
            'transcoder' => 'ffmpeg',
            'definition' => self::json([
                'transcoder' => 'ffmpeg',
                'source' => null,
                'renditions' => $renditions,
                'main' => null,
            ]),
            'output_source' => ($original !== null ? Row::string($original, 'source') : null) ?? $this->defaultSource(),
            'path_prefix' => $path !== null && dirname($path) !== '.' ? dirname($path) : '',
            'visibility' => Visibility::Public->value,
            'status' => $status->value,
            'progress' => $status === PipelineStatus::Completed ? 1 : null,
            'attempts' => Row::string($run, 'started_at') !== null ? 1 : 0,
            'error' => $status === PipelineStatus::Failed ? self::json(self::error(Row::string($run, 'failed_exception'), 'The run failed in 1.x.')) : null,
        ]);

        foreach ($jobs as $job) {
            $jobStatus = match (true) {
                Row::bool($job, 'failed') => JobStatus::Failed,
                Row::bool($job, 'ended') => JobStatus::Completed,
                default => JobStatus::Canceled,
            };

            $t->query('pipelines_jobs')->where('id', Row::int($job, 'id'))->update([
                'status' => $jobStatus->value,
                'progress' => $jobStatus === JobStatus::Completed ? 1 : null,
                'attempts' => Row::string($job, 'started_at') !== null ? 1 : 0,
                'error' => $jobStatus === JobStatus::Failed ? self::json(self::error(Row::string($job, 'failed_exception'), 'The rendition failed in 1.x.')) : null,
                'queued_at' => Row::string($job, 'created_at'),
            ]);
        }
    }

    /**
     * Remove the rows that refer to nothing, which would block the foreign
     * keys: attachments of deleted media, runs of deleted media and their
     * jobs, jobs of deleted runs, and files that no media and no other model
     * attaches. Stored files are not deleted. The `metadatas` and
     * `files_pivot` tables are not used by 2.0, and are left as they are.
     *
     * @return array<string, int>
     */
    public function removeOrphans(): array
    {
        $t = $this->tables;
        $removed = [];

        $removed['medias_pivot'] = $this->missing($t->query('medias_pivot'), 'medias', $t->table('medias_pivot').'.media_id')->delete();

        $runs = $this->missing($t->query('pipelines')->whereIn('pipelinable_type', $t->mediaTypes), 'medias', $t->table('pipelines').'.pipelinable_id');
        $runIds = $runs->pluck('id')->all();
        $jobs = 0;
        foreach (array_chunk($runIds, $this->batch) as $ids) {
            $jobs += $t->query('pipelines_jobs')->whereIn('pipeline_id', $ids)->delete();
            $t->query('pipelines')->whereIn('id', $ids)->delete();
        }
        $removed['pipelines'] = count($runIds);
        $removed['pipelines_jobs'] = $jobs + $this->missing($t->query('pipelines_jobs'), 'pipelines', $t->table('pipelines_jobs').'.pipeline_id')->delete();

        $files = (new Inspector($t, $this->config))->unattachedFiles()->pluck('f.id')->all();
        foreach (array_chunk($files, $this->batch) as $ids) {
            $t->query('files')->whereIn('id', $ids)->delete();
        }
        $removed['files'] = count($files);

        return $removed;
    }

    /**
     * Add the foreign keys that a new install has and that existing rows
     * could break. Returns the ones it added, as `table.column`.
     *
     * @return list<string>
     */
    public function addForeignKeys(): array
    {
        $added = [];
        $schema = Schema::connection($this->tables->db->getName());

        foreach (self::FOREIGN_KEYS as [$table, $column, $references]) {
            $name = $this->tables->table($table);
            $exists = array_filter($schema->getForeignKeys($name), fn (array $key) => $key['columns'] === [$column]);

            if ($exists !== []) {
                continue;
            }

            $schema->table($name, function (Blueprint $blueprint) use ($column, $references) {
                $blueprint->foreign($column)->references('id')->on($this->tables->table($references))->cascadeOnDelete();
            });
            $added[] = $name.'.'.$column;
        }

        return $added;
    }

    /**
     * Make the 1.x `definition` column of runs a JSON column, as on a new
     * install, once every run has a JSON definition. False when it is one
     * already, or when runs are left to convert. SQLite has no JSON type.
     */
    public function convertDefinitionColumn(): bool
    {
        $schema = Schema::connection($this->tables->db->getName());
        $table = $this->tables->table('pipelines');
        $driver = $this->tables->db->getDriverName();

        if ($driver === 'sqlite' || $this->tables->query('pipelines')->whereNull('uid')->exists()) {
            return false;
        }

        $types = array_column($schema->getColumns($table), 'type_name', 'name');
        if (in_array(strtolower((string) ($types['definition'] ?? '')), ['json', 'jsonb'], true)) {
            return false;
        }

        $schema->table($table, function (Blueprint $table) use ($driver) {
            $driver === 'pgsql'
                ? $table->jsonb('definition')->nullable()->using('"definition"::jsonb')->change()
                : $table->json('definition')->nullable()->change();
        });

        return true;
    }

    /**
     * Whether every media and every run is converted.
     */
    public function isComplete(): bool
    {
        return ! $this->tables->query('medias')->whereNull('uid')->exists()
            && ! $this->tables->query('pipelines')->whereNull('uid')->exists();
    }

    /**
     * The metadata of the 1.x rows of these models, with the canonical
     * keys, by model id.
     *
     * @param  list<string>  $types
     * @param  array<mixed>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function metadataOf(array $types, array $ids): array
    {
        if ($ids === [] || ! $this->tables->hasTable('metadatas')) {
            return [];
        }

        $metadata = [];
        $rows = $this->tables->query('metadatas')
            ->whereIn('morphable_type', $types)
            ->whereIn('morphable_id', $ids)
            ->orderBy('id')
            ->get();

        foreach ($rows->groupBy(fn (stdClass $row) => (int) Row::int($row, 'morphable_id')) as $id => $rowsOfModel) {
            $metadata[(int) $id] = LegacyMetadata::toArray($rowsOfModel);
        }

        return $metadata;
    }

    /**
     * Whether the last 1.x run of each media failed, by media id.
     *
     * @param  array<mixed>  $ids
     * @return array<int, bool>
     */
    private function lastRunFailed(array $ids): array
    {
        $t = $this->tables;
        $last = $t->query('pipelines')
            ->whereIn('pipelinable_type', $t->mediaTypes)
            ->whereIn('pipelinable_id', $ids)
            ->groupBy('pipelinable_id')
            ->selectRaw('max(id) as id');

        $failed = [];
        foreach ($t->query('pipelines')->whereIn('id', $last)->get(['pipelinable_id', 'failed']) as $run) {
            $failed[(int) Row::int($run, 'pipelinable_id')] = Row::bool($run, 'failed');
        }

        return $failed;
    }

    private function missing(Builder $query, string $table, string $column): Builder
    {
        return $query->whereNotExists(fn (Builder $exists) => $exists
            ->selectRaw('1')
            ->from($this->tables->table($table).' as missing_'.$table)
            ->whereColumn('missing_'.$table.'.id', $column));
    }

    private function defaultSource(): string
    {
        $source = config('mediatheque.source');

        return is_string($source) ? $source : '';
    }

    /**
     * @return array{code: string, message: string}
     */
    private static function error(?string $exception, string $default): array
    {
        $message = $exception !== null && trim($exception) !== '' ? trim($exception) : $default;

        return [
            'code' => TranscoderErrorCode::OperationFailed->value,
            'message' => mb_strcut($message, 0, self::MESSAGE_LENGTH),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
