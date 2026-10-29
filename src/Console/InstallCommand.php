<?php

namespace Folklore\Mediatheque\Console;

use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Publishes the config and the migrations (docs/decisions/0015). The package
 * never loads its migrations: the application owns its schema. Running the
 * command again publishes only the migrations the application doesn't have.
 *
 * With `--upgrade`, for an application that has the 1.x tables, it publishes
 * the migration that adds the 2.0 columns to them instead of the one that
 * creates the tables (docs/decisions/0002, docs/upgrade.md).
 */
#[AsCommand(name: 'mediatheque:install')]
class InstallCommand extends Command
{
    private const string UPGRADE_MIGRATION = '2026_10_16_000001_upgrade_mediatheque_tables_from_1x.php';

    protected $signature = 'mediatheque:install
                            {--force : Overwrite the published config}
                            {--upgrade : Convert the tables of mediatheque 1.x instead of creating them}
                            {--dashboard : Publish a provider that defines who can use the monitoring dashboard}';

    protected $description = 'Publish the mediatheque config and migrations';

    public function handle(Filesystem $files, MediathequeConfig $config): int
    {
        $upgrade = (bool) $this->option('upgrade');

        if (! $upgrade && $this->hasTablesOf1x($config)) {
            $this->components->error('The mediatheque 1.x tables exist: run `php artisan mediatheque:install --upgrade` to convert them (docs/upgrade.md).');

            return self::FAILURE;
        }

        if (! $upgrade) {
            $this->publishConfig($files);
        }

        $published = $this->publishMigrations($files, $upgrade);

        if ($this->option('dashboard')) {
            $this->publishDashboardProvider($files);
        }

        try {
            $config->validate();
        } catch (InvalidConfig $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info(match (true) {
            $published === 0 => 'The migrations are already published.',
            $upgrade => 'Run `php artisan migrate`, then `php artisan mediatheque:upgrade` to convert the 1.x data.',
            default => 'Run `php artisan migrate` to create the mediatheque tables.',
        });
        $this->components->info('Schedule `mediatheque:prune` daily, in routes/console.php: Schedule::command(\'mediatheque:prune\')->daily();');

        return self::SUCCESS;
    }

    private function publishConfig(Filesystem $files): void
    {
        $target = $this->laravel->configPath('mediatheque.php');

        if ($files->exists($target) && ! $this->option('force')) {
            $this->components->twoColumnDetail('config/mediatheque.php', '<fg=yellow;options=bold>EXISTS</>');

            return;
        }

        $files->copy(self::packagePath('config/mediatheque.php'), $target);
        $this->components->twoColumnDetail('config/mediatheque.php', '<fg=green;options=bold>PUBLISHED</>');
    }

    /**
     * The provider that defines the dashboard's gates (docs/decisions/0017),
     * registered in `bootstrap/providers.php`. Its gates let no one in until
     * the application lists who can; without them, the dashboard is open in
     * `local` only.
     */
    private function publishDashboardProvider(Filesystem $files): void
    {
        $target = $this->laravel->path('Providers/MediathequeServiceProvider.php');

        if ($files->exists($target)) {
            $this->components->twoColumnDetail('app/Providers/MediathequeServiceProvider.php', '<fg=yellow;options=bold>EXISTS</>');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy(self::packagePath('stubs/MediathequeServiceProvider.stub'), $target);
        ServiceProvider::addProviderToBootstrapFile('App\\Providers\\MediathequeServiceProvider');
        $this->components->twoColumnDetail('app/Providers/MediathequeServiceProvider.php', '<fg=green;options=bold>PUBLISHED</>');
    }

    /**
     * Whether the database has the `medias` table of 1.x, which has no
     * `uid`. A database that can't be reached is not checked.
     */
    private function hasTablesOf1x(MediathequeConfig $config): bool
    {
        try {
            $medias = $config->tablePrefix().'medias';

            return Schema::hasTable($medias) && ! Schema::hasColumn($medias, 'uid');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Copy each package migration that the application doesn't have yet,
     * whatever its timestamp, with a fresh timestamp. The migration that
     * creates the tables and the one that upgrades the 1.x tables stand for
     * each other: an application has one of them.
     */
    private function publishMigrations(Filesystem $files, bool $upgrade): int
    {
        $directory = $this->laravel->databasePath('migrations');
        $files->ensureDirectoryExists($directory);

        $existing = array_map(fn (string $path) => self::withoutTimestamp($path), glob($directory.'/*.php') ?: []);
        $upgraded = in_array(self::withoutTimestamp(self::UPGRADE_MIGRATION), $existing, true);

        $time = Date::now();
        $published = 0;

        foreach ($upgrade || $upgraded ? self::upgradeMigrations() : self::migrations() as $migration) {
            $name = self::withoutTimestamp($migration);

            if (in_array($name, $existing, true)) {
                $this->components->twoColumnDetail('database/migrations/…_'.$name, '<fg=yellow;options=bold>EXISTS</>');

                continue;
            }

            $target = $directory.'/'.$time->format('Y_m_d_His').'_'.$name;
            $files->copy($migration, $target);
            $time = $time->addSecond();
            $published++;

            $this->components->twoColumnDetail('database/migrations/'.basename($target), '<fg=green;options=bold>PUBLISHED</>');
        }

        return $published;
    }

    /**
     * The package's migrations, in the order they run. A later alpha adds
     * its own at the end.
     *
     * Their file names start with a timestamp, so a migrator that loads them
     * from the package, as tests do, runs them in this order too. They are
     * published under their name without it, with a fresh one.
     *
     * @return list<string>
     */
    public static function migrations(): array
    {
        return array_map(fn (string $name) => self::packagePath('database/migrations/'.$name), [
            '2026_10_16_000000_create_mediatheque_tables.php',
            '2026_10_21_000000_update_mediatheque_tables_for_monitoring.php',
            '2026_10_21_000001_drop_mediatheque_uploads_table.php',
            '2026_10_22_000000_create_mediatheque_medias_pivot_table.php',
            '2026_10_25_000000_add_transform_to_mediatheque_medias_table.php',
            '2026_10_26_000000_use_jsonb_in_mediatheque_tables_on_postgresql.php',
            '2026_10_28_000000_make_mediatheque_progress_nullable.php',
        ]);
    }

    /**
     * The migrations of an application upgraded from 1.x: the one that adds
     * the 2.0 columns to the 1.x tables, in place of the one that creates
     * them, then the others.
     *
     * @return list<string>
     */
    public static function upgradeMigrations(): array
    {
        $migrations = self::migrations();
        $migrations[0] = self::packagePath('database/migrations/'.self::UPGRADE_MIGRATION);

        return $migrations;
    }

    /**
     * A migration's file name without its timestamp: the name the
     * application has it under, whenever it was published.
     */
    private static function withoutTimestamp(string $path): string
    {
        return (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path));
    }

    private static function packagePath(string $path): string
    {
        return Str::finish(dirname(__DIR__, 2), '/').$path;
    }
}
