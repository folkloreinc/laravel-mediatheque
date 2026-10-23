<?php

namespace Folklore\Mediatheque\Tests\Feature\Console;

use Folklore\Mediatheque\Console\InstallCommand;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

class InstallCommandTest extends TestCase
{
    private string $migrations;

    protected function setUp(): void
    {
        parent::setUp();

        app()->useDatabasePath(sys_get_temp_dir().'/mediatheque-install-'.bin2hex(random_bytes(4)));
        $this->migrations = app()->databasePath('migrations');
        (new Filesystem)->deleteDirectory(app()->databasePath());
        @unlink(config_path('mediatheque.php'));
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory(app()->databasePath());
        @unlink(config_path('mediatheque.php'));

        parent::tearDown();
    }

    private function install(): void
    {
        $this->assertSame(0, Artisan::call('mediatheque:install'));
    }

    /**
     * @return list<string>
     */
    private function published(): array
    {
        return array_map('basename', glob($this->migrations.'/*.php') ?: []);
    }

    #[Test]
    public function it_publishes_the_config_and_the_migrations_with_a_timestamp(): void
    {
        $this->install();

        $this->assertFileExists(config_path('mediatheque.php'));
        $published = $this->published();
        sort($published);
        $this->assertCount(4, $published);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_mediatheque_tables\.php$/', $published[0]);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_update_mediatheque_tables_for_monitoring\.php$/', $published[1], 'Later migrations run after the first one.');
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_drop_mediatheque_uploads_table\.php$/', $published[2]);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_mediatheque_medias_pivot_table\.php$/', $published[3]);
    }

    #[Test]
    public function running_it_again_publishes_nothing_twice(): void
    {
        $this->install();
        file_put_contents(config_path('mediatheque.php'), "<?php\n\nreturn ['source' => 'local'];\n");

        $this->install();

        $this->assertCount(4, $this->published());
        $this->assertStringContainsString("'local'", (string) file_get_contents(config_path('mediatheque.php')));
    }

    #[Test]
    public function an_application_installed_by_an_earlier_alpha_gets_the_new_migrations_only(): void
    {
        $this->install();
        foreach (glob($this->migrations.'/*_create_mediatheque_medias_pivot_table.php') ?: [] as $later) {
            unlink($later);
        }
        $earlier = $this->published();

        $this->install();

        $published = $this->published();
        $this->assertCount(4, $published);
        $this->assertSame($earlier, array_values(array_intersect($published, $earlier)), 'Published migrations are left as they are.');
    }

    #[Test]
    public function the_published_migration_creates_and_drops_the_tables(): void
    {
        $this->install();
        $this->startFromAnEmptyDatabase();

        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);

        foreach (['medias', 'files', 'pipelines', 'pipelines_jobs', 'medias_pivot'] as $table) {
            $this->assertTrue(Schema::hasTable('mediatheque_'.$table), $table);
        }
        $this->assertTrue(Schema::hasColumns('mediatheque_files', ['media_id', 'handle', 'index', 'role', 'size', 'checksum', 'definition_hash']));
        $this->assertTrue(Schema::hasColumns('mediatheque_pipelines_jobs', ['queued_at']));
        $this->assertFalse(Schema::hasTable('mediatheque_uploads'));
        $this->assertTrue(Schema::hasIndex('mediatheque_pipelines', ['ended_at']));
        $this->assertTrue(Schema::hasColumns('mediatheque_medias_pivot', ['media_id', 'morphable_type', 'morphable_id', 'handle', 'order']));

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true]);

        $this->assertFalse(Schema::hasTable('mediatheque_medias'));
    }

    #[Test]
    public function index_and_foreign_key_names_fit_in_mysql_identifiers_with_a_longer_prefix(): void
    {
        $prefix = 'mediatheque_app_';
        config(['mediatheque.table_prefix' => $prefix]);
        $this->install();
        $this->startFromAnEmptyDatabase();

        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);

        $names = [];
        foreach (['medias', 'files', 'pipelines', 'pipelines_jobs', 'medias_pivot'] as $table) {
            $this->assertTrue(Schema::hasTable($prefix.$table), $table);
            foreach ([...Schema::getIndexes($prefix.$table), ...Schema::getForeignKeys($prefix.$table)] as $constraint) {
                if (is_string($constraint['name'] ?? null)) {
                    $names[] = $constraint['name'];
                }
            }
        }

        $this->assertContains($prefix.'medias_pivot_usage_unique', $names);
        $this->assertSame([], array_values(array_filter($names, fn (string $name) => strlen($name) > 64)), 'MySQL refuses identifiers longer than 64 characters.');

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true]);
    }

    #[Test]
    public function a_migrator_that_loads_the_migrations_from_the_package_runs_them_in_their_order(): void
    {
        $migrations = InstallCommand::migrations();
        $sorted = $migrations;
        usort($sorted, fn (string $a, string $b) => basename($a) <=> basename($b));

        // Migrators sort by file name: the pivot's foreign key needs the media
        // table first, which MySQL checks and SQLite does not
        $this->assertSame($migrations, $sorted);
    }

    #[Test]
    public function the_package_does_not_load_its_migrations(): void
    {
        $package = (string) realpath(__DIR__.'/../../../database/migrations');

        $this->assertNotContains($package, array_map(fn (string $path) => realpath($path), app()->make('migrator')->paths()));
    }

    #[Test]
    public function with_dashboard_it_publishes_and_registers_a_provider_of_the_dashboard_gates(): void
    {
        $root = sys_get_temp_dir().'/mediatheque-app-'.bin2hex(random_bytes(4));
        $files = new Filesystem;
        $files->ensureDirectoryExists($root.'/bootstrap');
        file_put_contents($root.'/bootstrap/providers.php', "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n");
        app()->useAppPath($root.'/app');
        app()->useBootstrapPath($root.'/bootstrap');

        try {
            $first = Artisan::call('mediatheque:install', ['--dashboard' => true]);
            $again = Artisan::call('mediatheque:install', ['--dashboard' => true]);
            $this->assertSame([0, 0], [$first, $again]);

            $provider = (string) file_get_contents($root.'/app/Providers/MediathequeServiceProvider.php');
            $this->assertStringContainsString("Gate::define('viewMediathequeDashboard'", $provider);
            $this->assertStringContainsString("Gate::define('operateMediathequeDashboard'", $provider);
            $this->assertSame(1, substr_count((string) file_get_contents($root.'/bootstrap/providers.php'), 'MediathequeServiceProvider'));
        } finally {
            $files->deleteDirectory($root);
        }
    }

    #[Test]
    public function without_dashboard_it_publishes_no_provider(): void
    {
        $this->install();

        $this->assertFileDoesNotExist(app_path('Providers/MediathequeServiceProvider.php'));
    }

    #[Test]
    public function an_application_upgraded_from_1x_keeps_its_medias_pivot_migration(): void
    {
        (new Filesystem)->ensureDirectoryExists($this->migrations);
        copy(
            self::fixturePath('v1/migrations/2017_07_25_100001_create_mediatheque_medias_pivot_table.php'),
            $this->migrations.'/2017_07_25_100001_create_mediatheque_medias_pivot_table.php',
        );

        $this->install();

        $this->assertCount(1, glob($this->migrations.'/*_create_mediatheque_medias_pivot_table.php') ?: []);
    }
}
