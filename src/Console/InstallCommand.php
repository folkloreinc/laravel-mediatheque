<?php

namespace Folklore\Mediatheque\Console;

use Folklore\Mediatheque\Exceptions\InvalidConfig;
use Folklore\Mediatheque\Support\MediathequeConfig;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Date;
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
                            {--force : Overwrite the published config}';

    protected $description = 'Publish the mediatheque config and migrations';

    public function handle(Filesystem $files, MediathequeConfig $config): int
    {
        $this->publishConfig($files);
        $published = $this->publishMigrations($files);

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
     * Copy each package migration that the application doesn't have yet,
     * whatever its timestamp, with a fresh timestamp.
     */
    private function publishMigrations(Filesystem $files): int
    {
        $directory = $this->laravel->databasePath('migrations');
        $files->ensureDirectoryExists($directory);

        $existing = array_map(
            fn (string $path) => (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($path)),
            glob($directory.'/*.php') ?: [],
        );

        $time = Date::now();
        $published = 0;

        foreach (self::migrations() as $migration) {
            $name = basename($migration);

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
     * @return list<string>
     */
    public static function migrations(): array
    {
        return array_map(fn (string $name) => self::packagePath('database/migrations/'.$name), [
            'create_mediatheque_tables.php',
        ]);
    }

    private static function packagePath(string $path): string
    {
        return Str::finish(dirname(__DIR__, 2), '/').$path;
    }
}
