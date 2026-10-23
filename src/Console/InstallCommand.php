<?php

namespace Folklore\Mediatheque\Console;

use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Publishes the config and the migrations (docs/decisions/0015). The package
 * never loads its migrations: the application owns its schema. Running the
 * command again publishes only the migrations the application doesn't have.
 */
#[AsCommand(name: 'mediatheque:install')]
class InstallCommand extends Command
{
    protected $signature = 'mediatheque:install
                            {--force : Overwrite the published config}
                            {--dashboard : Publish a provider that defines who can use the monitoring dashboard}';

    protected $description = 'Publish the mediatheque config and migrations';

    public function handle(Filesystem $files, MediathequeConfig $config): int
    {
        $this->publishConfig($files);
        $published = $this->publishMigrations($files);

        if ($this->option('dashboard')) {
            $this->publishDashboardProvider($files);
        }

        try {
            $config->validate();
        } catch (InvalidConfig $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($published > 0
            ? 'Run `php artisan migrate` to create the mediatheque tables.'
            : 'The migrations are already published.');

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
     * Copy each package migration that the application doesn't have yet,
     * whatever its timestamp, with a fresh timestamp.
     */
    private function publishMigrations(Filesystem $files): int
    {
        $directory = $this->laravel->databasePath('migrations');
        $files->ensureDirectoryExists($directory);

        $existing = array_map(fn (string $path) => self::withoutTimestamp($path), glob($directory.'/*.php') ?: []);

        $time = Date::now();
        $published = 0;

        foreach (self::migrations() as $migration) {
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
        ]);
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
