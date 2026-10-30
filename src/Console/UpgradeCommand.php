<?php

namespace Folklore\Mediatheque\Console;

use Folklore\Mediatheque\Compat\LegacyConfig;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Folklore\Mediatheque\Upgrade\Converter;
use Folklore\Mediatheque\Upgrade\Inspector;
use Folklore\Mediatheque\Upgrade\LegacyConfigFile;
use Folklore\Mediatheque\Upgrade\LegacyTables;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Converts a 1.x database to 2.0 (docs/decisions/0002, docs/upgrade.md):
 *
 * - `--check` lists what the upgrade would remove, can't convert, or leaves
 *   to the application, and changes nothing;
 * - without an option, it converts the data, in resumable batches, then
 *   removes orphan rows and adds the foreign keys;
 * - `--config` rewrites a 1.x `config/mediatheque.php` in the 2.0 shape,
 *   with only the application's overrides;
 * - `--cleanup` drops the 1.x `metadatas` table once the data is converted.
 */
#[AsCommand(name: 'mediatheque:upgrade')]
class UpgradeCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'mediatheque:upgrade
                            {--check : List what the upgrade would change or can\'t convert, and change nothing}
                            {--config : Rewrite config/mediatheque.php in the 2.0 shape, with only your overrides}
                            {--cleanup : Drop the 1.x metadatas table, once the converted data is checked}
                            {--batch=500 : The number of rows converted in each transaction}
                            {--media-morph=* : Another morph type that 1.x rows use for media}
                            {--file-morph=* : Another morph type that 1.x rows use for files}
                            {--accept-unknown-morphs : Convert although metadata of morph types neither media nor files would not be copied}
                            {--force : Run without asking for confirmation}';

    protected $description = 'Convert the mediatheque 1.x data to 2.0';

    public function handle(MediathequeConfig $config, Filesystem $files): int
    {
        $modes = array_keys(array_filter(['check' => $this->option('check'), 'config' => $this->option('config'), 'cleanup' => $this->option('cleanup')]));
        if (count($modes) > 1) {
            $this->components->error(sprintf('--%s can\'t be combined: run one at a time.', implode(' and --', $modes)));

            return self::FAILURE;
        }

        if ($this->option('config')) {
            return $this->rewriteConfig($files);
        }

        $tables = LegacyTables::make($this->strings('media-morph'), $this->strings('file-morph'));

        if ($this->option('check')) {
            return $this->check($tables, $config, $files);
        }

        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if (! is_int($batch) || $batch < 1) {
            $this->components->error('--batch is a positive number of rows.');

            return self::FAILURE;
        }

        $converter = new Converter($tables, $config, $batch);

        $missing = $converter->missingColumns();
        if ($missing !== []) {
            $this->components->error('The 2.0 columns are missing ('.implode(', ', array_slice($missing, 0, 3)).(count($missing) > 3 ? '…' : '').'): run `php artisan mediatheque:install --upgrade`, then `php artisan migrate`.');

            return self::FAILURE;
        }

        if ($this->option('cleanup')) {
            return $this->cleanup($tables, $converter, $config);
        }

        $unknown = (new Inspector($tables, $config))->unknownMetadataTypes();
        if ($unknown !== [] && ! $this->option('accept-unknown-morphs')) {
            $this->components->error('The metadata of these morph types is neither media\'s nor files\', and would not be copied: '.implode(', ', array_map(fn (string $type, int $count) => sprintf('%s (%d rows)', $type, $count), array_keys($unknown), $unknown)).'. Pass the types 1.x used for media or files with --media-morph or --file-morph, or --accept-unknown-morphs to convert without that metadata.');

            return self::FAILURE;
        }

        if (! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        return $this->convert($converter, $tables);
    }

    private function check(LegacyTables $tables, MediathequeConfig $config, Filesystem $files): int
    {
        $report = (new Inspector($tables, $config))->inspect();

        $this->components->info('Media are recognized by these morph types: '.implode(', ', $tables->mediaTypes).'; files by: '.implode(', ', $tables->fileTypes).'.');

        $legacy = $this->legacyConfig($files);
        $conflicts = $legacy !== null ? LegacyConfig::convert($legacy)->conflicts : [];
        foreach ($conflicts as $conflict) {
            $this->components->warn($conflict);
        }

        if ($report->isClean()) {
            $this->components->info($conflicts === [] ? 'Nothing to remove, and nothing the upgrade can\'t convert.' : 'Nothing to remove in the database, and nothing the upgrade can\'t convert.');

            return self::SUCCESS;
        }

        $this->section('Orphan rows, removed by the upgrade (they block the foreign keys)', array_filter($report->orphans));

        foreach ($report->unknownMorphTypes as $table => $types) {
            $this->section(sprintf('Rows of %s on models that are neither media nor files, not converted: pass their type with --media-morph or --file-morph if they are', $tables->table($table)), $types);
        }

        if ($report->unreadableDefinitions > 0) {
            $this->section('Runs whose serialized definition can\'t be read: their renditions are read from their jobs', ['runs' => $report->unreadableDefinitions]);
        }

        $this->section('1.x jobs with no 2.0 operation, kept in the history of their runs: give a pipeline that needs one a custom operation', $report->jobsWithoutOperation, 'runs');
        $this->section('Handles that break the 2.0 rule (a letter, then letters, digits, "_" and "-"): rename those renditions', self::labels($report->invalidHandles, '(none)'), 'files');
        $this->section('Files attached to models other than media, left as they are: 2.0 attaches files to media only', $report->filesOnOtherModels, 'rows');

        if ($report->filesWithoutSource > 0) {
            $this->section($report->defaultSource !== ''
                ? sprintf('Files without a source, which 1.x stored on its default source: they get "%s", the disk of that name', $report->defaultSource)
                : 'Files without a source, which 1.x stored on its default source: set `source` in config/mediatheque.php, or they keep none and get no URL', ['files' => $report->filesWithoutSource]);
        }

        if ($report->filesOfSeveralMedia > 0) {
            $this->section('Files attached to several media: each goes to the first', ['files' => $report->filesOfSeveralMedia]);
        }

        if ($report->typesWithoutRenditions !== []) {
            $this->newLine();
            $this->components->warn('Media whose type has no 2.0 renditions, or fewer than in 1.x: their files are kept');
            foreach ($report->typesWithoutRenditions as $type => $without) {
                $this->components->twoColumnDetail(sprintf('%s (%d media)', $type, $without['count']), $without['reason']);
            }
        }

        return self::SUCCESS;
    }

    private function convert(Converter $converter, LegacyTables $tables): int
    {
        $this->components->twoColumnDetail('Files without a source: the default source', $converter->fillSources().' given "'.$tables->defaultSource().'"');
        $this->components->twoColumnDetail('Files: media, handles and metadata', $converter->convertFiles().' converted');
        $this->components->twoColumnDetail('Media: uid, status and metadata', $converter->convertMedia().' converted');
        $this->components->twoColumnDetail('Runs: statuses and JSON definitions', $converter->convertRuns().' converted');

        foreach ($converter->removeOrphans() as $table => $count) {
            $this->components->twoColumnDetail('Orphan rows of '.$table, $count.' removed');
        }

        $keys = $converter->addForeignKeys();
        $this->components->twoColumnDetail('Foreign keys', $keys !== [] ? implode(', ', $keys) : 'added already');

        if ($converter->convertDefinitionColumn()) {
            $this->components->twoColumnDetail('Definitions of runs', 'JSON column');
        }

        if (! $converter->isComplete()) {
            $this->components->warn('Some rows are left to convert: run the command again.');

            return self::FAILURE;
        }

        $this->components->info('The 1.x data is converted. Check it, then drop the 1.x metadatas table with `php artisan mediatheque:upgrade --cleanup`.');

        return self::SUCCESS;
    }

    private function cleanup(LegacyTables $tables, Converter $converter, MediathequeConfig $config): int
    {
        if (! $converter->isComplete()) {
            $this->components->error('Some rows are not converted yet: run `php artisan mediatheque:upgrade` first.');

            return self::FAILURE;
        }

        if (! $tables->hasTable('metadatas')) {
            $this->components->info('The 1.x metadatas table is dropped already.');

            return self::SUCCESS;
        }

        $uncopied = (new Inspector($tables, $config))->uncopiedMetadata();
        if ($uncopied !== []) {
            $this->components->error(sprintf('%s holds metadata that no converted media or file has: dropping it would lose it.', $tables->table('metadatas')));
            foreach ($uncopied as $reason => $count) {
                $this->components->twoColumnDetail('Rows '.$reason, (string) $count);
            }
            $this->components->info('Convert their models first (--media-morph, --file-morph), or delete those rows yourself once you no longer need them.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(sprintf('Drop the table %s? Its data was copied to the metadata columns.', $tables->table('metadatas')))) {
            return self::FAILURE;
        }

        $tables->db->getSchemaBuilder()->drop($tables->table('metadatas'));
        $this->components->info(sprintf('Dropped %s.', $tables->table('metadatas')));

        return self::SUCCESS;
    }

    /**
     * Rewrite `config/mediatheque.php` in the 2.0 shape, with only the
     * application's overrides. The 1.x file is kept next to it, with a name
     * Laravel doesn't load. The file is read with the defaults of its
     * `env()` calls, and the keys it converts keep reading their variable:
     * the rewritten file is loaded by every environment, not only this one.
     */
    private function rewriteConfig(Filesystem $files): int
    {
        $path = $this->laravel->configPath('mediatheque.php');

        if (! $files->exists($path)) {
            $this->components->error('There is no config/mediatheque.php: the package defaults apply.');

            return self::FAILURE;
        }

        try {
            // As written, not as this machine's environment makes it
            $file = LegacyConfigFile::read($path);
        } catch (RuntimeException) {
            $this->components->error('config/mediatheque.php doesn\'t return an array.');

            return self::FAILURE;
        }

        if (! LegacyConfig::isLegacy($file->config)) {
            $this->components->info('config/mediatheque.php has the 2.0 shape already.');

            return self::SUCCESS;
        }

        $conversion = LegacyConfig::convert($file->config, null, $file->env);

        // Disks are compared with config/filesystems.php as this machine
        // reads it: the 1.x sources too, as --check and the boot do
        $live = require $path;
        $conflicts = is_array($live) ? LegacyConfig::convert($live)->conflicts : $conversion->conflicts;
        $notices = array_values(array_unique([...$conflicts, ...array_diff($conversion->notices, $conversion->conflicts)]));
        $backup = $path.'.1x.bak';
        $files->copy($path, $backup);
        $files->put($path, $conversion->toPhp());

        $this->components->info('Rewrote config/mediatheque.php. The 1.x file is in config/mediatheque.php.1x.bak.');

        foreach ($notices as $notice) {
            $this->components->warn($notice);
        }

        $unread = array_values(array_diff($file->variables, $conversion->env));
        if ($unread !== []) {
            $this->components->warn(sprintf('The 1.x file read these environment variables, which the rewritten one doesn\'t: %s. Their keys are dropped, or written with the default of their env() call.', implode(', ', $unread)));
        }

        if ($file->code !== []) {
            $this->components->warn('These lines of the 1.x file read this machine, not the file: the values they gave are written as they are here. Check them in the rewritten file and the disks below.');
            foreach ($file->code as $line => $code) {
                $this->components->twoColumnDetail('config/mediatheque.php.1x.bak:'.$line, $code);
            }
        }

        if ($conversion->disks !== []) {
            $this->components->warn('2.0 stores files on Laravel disks: add these disks to config/filesystems.php, which 1.x sources defined. They are printed with values, not the env() calls of the 1.x sources: write an env() call again where environments differ.');
            $this->line($conversion->disksToPhp());
        }

        return self::SUCCESS;
    }

    /**
     * The application's config file, when it has the 1.x shape.
     *
     * @return array<array-key, mixed>|null
     */
    private function legacyConfig(Filesystem $files): ?array
    {
        $path = $this->laravel->configPath('mediatheque.php');
        $config = $files->exists($path) ? require $path : null;

        return is_array($config) && LegacyConfig::isLegacy($config) ? $config : null;
    }

    /**
     * @param  array<string, int>  $rows
     */
    private function section(string $title, array $rows, string $unit = ''): void
    {
        if ($rows === []) {
            return;
        }

        $this->newLine();
        $this->components->warn($title);
        foreach ($rows as $label => $count) {
            $this->components->twoColumnDetail($label, trim($count.' '.$unit));
        }
    }

    /**
     * @param  array<string, int>  $rows
     * @return array<string, int>
     */
    private static function labels(array $rows, string $empty): array
    {
        $labeled = [];
        foreach ($rows as $label => $count) {
            $labeled[$label === '' ? $empty : $label] = $count;
        }

        return $labeled;
    }

    /**
     * @return list<string>
     */
    private function strings(string $option): array
    {
        $values = $this->option($option);

        return is_array($values) ? array_values(array_filter($values, fn (mixed $value) => is_string($value) && $value !== '')) : [];
    }
}
