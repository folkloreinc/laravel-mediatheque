<?php

namespace Folklore\Mediatheque\Upgrade;

use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Reads a 1.x database and lists what `mediatheque:upgrade` would remove,
 * can't convert, or leaves to the application (docs/upgrade.md). It changes
 * nothing, and reads the 1.x columns only, so it runs before the migration
 * as well as after it.
 */
final readonly class Inspector
{
    private const int BATCH = 500;

    public function __construct(
        private LegacyTables $tables,
        private MediathequeConfig $config,
    ) {}

    public function inspect(): Report
    {
        return new Report(
            orphans: $this->orphans(),
            unknownMorphTypes: $this->unknownMorphTypes(),
            unreadableDefinitions: $this->unreadableDefinitions(),
            jobsWithoutOperation: $this->jobsWithoutOperation(),
            invalidHandles: $this->invalidHandles(),
            filesOnOtherModels: $this->filesOnOtherModels(),
            filesOfSeveralMedia: $this->filesOfSeveralMedia(),
            typesWithoutRenditions: $this->typesWithoutRenditions(),
        );
    }

    /**
     * Rows that refer to a media, a file or a run that doesn't exist, by
     * table: they block the foreign keys, and the upgrade removes them. The
     * metadata stays in the `metadatas` table until `--cleanup` drops it.
     *
     * @return array<string, int>
     */
    private function orphans(): array
    {
        $t = $this->tables;
        $orphans = [];

        if ($t->hasTable('metadatas')) {
            $orphans['metadatas'] = $t->query('metadatas', 'm')
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $this->missing($query->whereIn('m.morphable_type', $t->mediaTypes), 'medias', 'm.morphable_id'))
                    ->orWhere(fn (Builder $query) => $this->missing($query->whereIn('m.morphable_type', $t->fileTypes), 'files', 'm.morphable_id')))
                ->count();
        }

        $orphans['medias_pivot'] = $this->missing($t->query('medias_pivot', 'mp'), 'medias', 'mp.media_id')->count();

        if ($t->hasTable('files_pivot')) {
            $orphans['files_pivot'] = $t->query('files_pivot', 'fp')
                ->whereIn('fp.morphable_type', $t->mediaTypes)
                ->where(fn (Builder $query) => $query
                    ->where(fn (Builder $query) => $this->missing($query, 'medias', 'fp.morphable_id'))
                    ->orWhere(fn (Builder $query) => $this->missing($query, 'files', 'fp.file_id')))
                ->count();
        }

        $orphans['pipelines'] = $this->missing($t->query('pipelines', 'p')->whereIn('p.pipelinable_type', $t->mediaTypes), 'medias', 'p.pipelinable_id')->count();
        $orphans['pipelines_jobs'] = $this->missing($t->query('pipelines_jobs', 'j'), 'pipelines', 'j.pipeline_id')->count();
        $orphans['files'] = $this->unattachedFiles()->count();

        return $orphans;
    }

    /**
     * The files that no media and no other model attaches. A file that 1.x
     * attached to a media that is deleted is one of them.
     */
    public function unattachedFiles(): Builder
    {
        $t = $this->tables;
        $files = $t->query('files', 'f');

        if ($t->hasColumn('files', 'media_id')) {
            $files->where(fn (Builder $query) => $this->missing($query, 'medias', 'f.media_id'));
        }

        if (! $t->hasTable('files_pivot')) {
            return $files;
        }

        return $files->whereNotExists(fn (Builder $query) => $query
            ->selectRaw('1')
            ->from($t->table('files_pivot').' as fp')
            ->whereColumn('fp.file_id', 'f.id')
            ->where(fn (Builder $query) => $query
                ->whereNotIn('fp.morphable_type', $t->mediaTypes)
                ->orWhereExists(fn (Builder $query) => $query
                    ->selectRaw('1')
                    ->from($t->table('medias').' as fm')
                    ->whereColumn('fm.id', 'fp.morphable_id'))));
    }

    /**
     * The morph types of metadata and runs that are neither the media's nor
     * the files', by table: those rows are not converted. Pass the types
     * 1.x used to the command (`--media-morph`, `--file-morph`).
     *
     * @return array<string, array<string, int>>
     */
    private function unknownMorphTypes(): array
    {
        $t = $this->tables;
        $unknown = [];

        if ($t->hasTable('metadatas')) {
            $unknown['metadatas'] = $this->countBy(
                $t->query('metadatas')->whereNotIn('morphable_type', [...$t->mediaTypes, ...$t->fileTypes]),
                'morphable_type',
            );
        }

        $unknown['pipelines'] = $this->countBy($t->query('pipelines')->whereNotIn('pipelinable_type', $t->mediaTypes), 'pipelinable_type');

        return array_filter($unknown);
    }

    /**
     * The runs whose serialized definition can't be read. Their renditions
     * are read from their jobs.
     */
    private function unreadableDefinitions(): int
    {
        $count = 0;

        $this->unconvertedRuns()->chunkById(self::BATCH, function ($runs) use (&$count) {
            foreach ($runs as $run) {
                if (LegacyPipelineDefinition::parse(Row::string($run, 'definition')) === null) {
                    $count++;
                }
            }
        });

        return $count;
    }

    /**
     * The 1.x job classes that have no 2.0 operation, with the number of runs
     * that ran them: custom jobs, `WebFonts`, `MediaConvert`. Their runs keep
     * them in their history; a pipeline that needs them gets a custom
     * operation.
     *
     * @return array<string, int>
     */
    private function jobsWithoutOperation(): array
    {
        $runs = [];

        $this->unconvertedRuns()->chunkById(self::BATCH, function ($rows) use (&$runs) {
            foreach ($rows as $run) {
                $definition = LegacyPipelineDefinition::parse(Row::string($run, 'definition'));
                foreach ($definition?->jobsWithoutOperation() ?? [] as $class) {
                    $runs[$class][(int) Row::int($run, 'id')] = true;
                }
            }
        });

        $jobs = $this->tables->query('pipelines_jobs')->whereNotNull('definition');
        if ($this->tables->hasColumn('pipelines', 'uid')) {
            $jobs->whereIn('pipeline_id', $this->unconvertedRuns()->select('id'));
        }

        $jobs->select(['id', 'pipeline_id', 'definition'])->chunkById(self::BATCH, function ($rows) use (&$runs) {
            foreach ($rows as $job) {
                $definition = json_decode((string) Row::string($job, 'definition'), true);
                $class = LegacyJobs::jobClass($definition);
                if ($class !== null && LegacyJobs::rendition($definition) === null) {
                    $runs[$class][(int) Row::int($job, 'pipeline_id')] = true;
                }
            }
        });

        $counts = array_map('count', $runs);
        ksort($counts);

        return $counts;
    }

    /**
     * The handles of files that break the 2.0 rule once `thumbnails.0` is
     * split, with the number of files. A file without a handle is listed
     * under an empty one.
     *
     * @return array<string, int>
     */
    private function invalidHandles(): array
    {
        $t = $this->tables;
        $query = $t->query('files', 'f');

        if ($t->hasTable('files_pivot')) {
            $query->leftJoin($t->table('files_pivot').' as fp', fn (JoinClause $join) => $join
                ->on('fp.file_id', '=', 'f.id')
                ->whereIn('fp.morphable_type', $t->mediaTypes))
                ->selectRaw('f.handle as file_handle, fp.handle as pivot_handle, count(*) as aggregate')
                ->groupBy('f.handle', 'fp.handle');
        } else {
            $query->selectRaw('f.handle as file_handle, null as pivot_handle, count(*) as aggregate')->groupBy('f.handle');
        }

        if ($t->hasColumn('files', 'index')) {
            // Converted already
            $query->whereNull('f.index');
        }

        $invalid = [];
        foreach ($query->get() as $row) {
            $handle = Row::string($row, 'file_handle') ?? Row::string($row, 'pivot_handle') ?? '';
            if ($handle === '' || ! LegacyHandle::parse($handle)->isValid()) {
                $invalid[$handle] = ($invalid[$handle] ?? 0) + (int) Row::int($row, 'aggregate');
            }
        }

        ksort($invalid);

        return $invalid;
    }

    /**
     * The files that `files_pivot` attaches to models other than media, by
     * morph type. 2.0 has no such attachment: they are left as they are.
     *
     * @return array<string, int>
     */
    private function filesOnOtherModels(): array
    {
        if (! $this->tables->hasTable('files_pivot')) {
            return [];
        }

        return $this->countBy($this->tables->query('files_pivot')->whereNotIn('morphable_type', $this->tables->mediaTypes), 'morphable_type');
    }

    /**
     * The files that `files_pivot` attaches to more than one media. In 2.0, a
     * file belongs to one media: the upgrade gives it to the first.
     */
    private function filesOfSeveralMedia(): int
    {
        if (! $this->tables->hasTable('files_pivot')) {
            return 0;
        }

        return $this->tables->db->query()->fromSub(
            $this->tables->query('files_pivot')
                ->select('file_id')
                ->whereIn('morphable_type', $this->tables->mediaTypes)
                ->groupBy('file_id')
                ->havingRaw('count(distinct morphable_id) > 1'),
            'shared',
        )->count();
    }

    /**
     * The types of the media that get no renditions in 2.0, with their
     * number of media and why. Their files are kept.
     *
     * @return array<string, array{count: int, reason: string}>
     */
    private function typesWithoutRenditions(): array
    {
        try {
            $types = $this->config->types();
            $error = null;
        } catch (InvalidConfig $exception) {
            $types = [];
            $error = $exception->getMessage();
        }

        $without = [];

        foreach ($this->countBy($this->tables->query('medias'), 'type') as $type => $count) {
            $reason = match (true) {
                $error !== null => 'The config can\'t be read: '.$error,
                ! isset($types[$type]) => 'Not a type of the 2.0 config: add it to `types`, or its media can\'t be ingested again.',
                $type === 'font' && $types[$type]->pipeline === null => '1.x made web fonts of them (WebFonts); 2.0 has no rendition for fonts, so new fonts are only probed.',
                $types[$type]->pipeline === null => 'Its 2.0 type has no pipeline: new media of this type are only probed.',
                default => null,
            };

            if ($reason !== null) {
                $without[$type] = ['count' => $count, 'reason' => $reason];
            }
        }

        return $without;
    }

    /**
     * The runs that the upgrade hasn't converted yet.
     */
    private function unconvertedRuns(): Builder
    {
        $runs = $this->tables->query('pipelines')->select(['id', 'definition']);

        return $this->tables->hasColumn('pipelines', 'uid') ? $runs->whereNull('uid') : $runs;
    }

    /**
     * The rows whose column refers to no row of a table.
     */
    private function missing(Builder $query, string $table, string $column): Builder
    {
        return $query->whereNotExists(fn (Builder $exists) => $exists
            ->selectRaw('1')
            ->from($this->tables->table($table).' as missing_'.$table)
            ->whereColumn('missing_'.$table.'.id', $column));
    }

    /**
     * @return array<string, int>
     */
    private function countBy(Builder $query, string $column): array
    {
        $counts = [];

        foreach ($query->select($column.' as value')->selectRaw('count(*) as aggregate')->groupBy($column)->orderBy($column)->get() as $row) {
            $counts[(string) Row::string($row, 'value')] = (int) Row::int($row, 'aggregate');
        }

        return $counts;
    }
}
