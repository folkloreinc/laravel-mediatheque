<?php

namespace Folklore\Mediatheque\Tests\Feature\Console;

use Folklore\Mediatheque\Console\InstallCommand;
use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
        $this->assertCount(7, $published);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_mediatheque_tables\.php$/', $published[0]);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_update_mediatheque_tables_for_monitoring\.php$/', $published[1], 'Later migrations run after the first one.');
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_drop_mediatheque_uploads_table\.php$/', $published[2]);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_mediatheque_medias_pivot_table\.php$/', $published[3]);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_add_transform_to_mediatheque_medias_table\.php$/', $published[4]);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_use_jsonb_in_mediatheque_tables_on_postgresql\.php$/', $published[5]);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_make_mediatheque_progress_nullable\.php$/', $published[6]);
    }

    #[Test]
    public function running_it_again_publishes_nothing_twice(): void
    {
        $this->install();
        file_put_contents(config_path('mediatheque.php'), "<?php\n\nreturn ['source' => 'local'];\n");

        $this->install();

        $this->assertCount(7, $this->published());
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
        $this->assertCount(7, $published);
        $this->assertSame($earlier, array_values(array_intersect($published, $earlier)), 'Published migrations are left as they are.');
    }

    #[Test]
    public function with_upgrade_it_publishes_the_migration_that_converts_the_1x_tables_and_keeps_the_config(): void
    {
        file_put_contents(config_path('mediatheque.php'), "<?php\n\nreturn ['table_prefix' => 'mediatheque_', 'sources' => []];\n");

        $this->assertSame(0, Artisan::call('mediatheque:install', ['--upgrade' => true]));

        $published = $this->published();
        sort($published);
        $this->assertCount(7, $published);
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_upgrade_mediatheque_tables_from_1x\.php$/', $published[0]);
        $this->assertSame([], preg_grep('/create_mediatheque_tables/', $published));
        $this->assertStringContainsString("'sources'", (string) file_get_contents(config_path('mediatheque.php')), 'The 1.x config is kept: --config rewrites it.');
        $this->assertStringContainsString('mediatheque:upgrade', Artisan::output());
    }

    #[Test]
    public function an_upgraded_application_never_gets_the_migration_that_creates_the_tables(): void
    {
        $this->assertSame(0, Artisan::call('mediatheque:install', ['--upgrade' => true]));
        foreach (glob($this->migrations.'/*_make_mediatheque_progress_nullable.php') ?: [] as $later) {
            unlink($later);
        }

        $this->install();

        $published = $this->published();
        $this->assertCount(7, $published);
        $this->assertSame([], preg_grep('/create_mediatheque_tables/', $published));
    }

    #[Test]
    public function without_upgrade_it_refuses_a_database_that_has_the_1x_tables(): void
    {
        $this->startFromAnEmptyDatabase();
        Artisan::call('migrate', ['--path' => self::fixturePath('v1/migrations'), '--realpath' => true]);

        $this->assertSame(1, Artisan::call('mediatheque:install'));

        $this->assertStringContainsString('mediatheque:install --upgrade', Artisan::output());
        $this->assertSame([], $this->published());
        Artisan::call('db:wipe');
    }

    #[Test]
    public function the_published_upgrade_migration_converts_the_1x_tables_and_rolls_back(): void
    {
        $this->startFromAnEmptyDatabase();
        Artisan::call('migrate', ['--path' => self::fixturePath('v1/migrations'), '--realpath' => true]);
        $this->assertSame(0, Artisan::call('mediatheque:install', ['--upgrade' => true]));

        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);

        $this->assertTrue(Schema::hasColumns('mediatheque_medias', ['uid', 'status', 'visibility', 'metadata', 'transform', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('mediatheque_files', ['media_id', 'index', 'role']));
        $this->assertTrue(Schema::hasColumns('mediatheque_pipelines_jobs', ['status', 'queued_at']));

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true]);

        $this->assertTrue(Schema::hasTable('mediatheque_medias'), 'The 1.x tables stay.');
        $this->assertFalse(Schema::hasColumn('mediatheque_medias', 'uid'));
        $this->assertFalse(Schema::hasColumn('mediatheque_files', 'media_id'));
        Artisan::call('db:wipe');
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
        $this->assertTrue(Schema::hasColumns('mediatheque_medias', ['transform']));
        $this->assertFalse(Schema::hasTable('mediatheque_uploads'));
        $this->assertTrue(Schema::hasIndex('mediatheque_pipelines', ['ended_at']));
        $this->assertTrue(Schema::hasColumns('mediatheque_medias_pivot', ['media_id', 'morphable_type', 'morphable_id', 'handle', 'order']));

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true]);

        $this->assertFalse(Schema::hasTable('mediatheque_medias'));
    }

    #[Test]
    public function the_json_columns_are_jsonb_on_postgresql_only(): void
    {
        $this->install();
        $this->startFromAnEmptyDatabase();

        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);
        $migrated = $this->jsonColumnTypes();
        // The jsonb migration, and the one after it
        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true, '--step' => 2]);
        $before = $this->jsonColumnTypes();

        if (DB::getDriverName() === 'pgsql') {
            $this->assertSame(array_fill_keys(array_keys($migrated), 'jsonb'), $migrated);
            $this->assertSame(array_fill_keys(array_keys($before), 'json'), $before, 'Rolled back, the columns are json again.');
        } else {
            $this->assertSame($before, $migrated, 'The migration changes nothing on other databases.');
        }

        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);
        $this->assertSame($migrated, $this->jsonColumnTypes(), 'It runs again after a rollback.');

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true]);
    }

    #[Test]
    public function on_postgresql_a_json_column_whose_type_changed_is_left_as_it_is(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('The jsonb migration changes PostgreSQL columns only.');
        }

        $this->install();
        $this->startFromAnEmptyDatabase();
        foreach (glob($this->migrations.'/*_use_jsonb_in_mediatheque_tables_on_postgresql.php') ?: [] as $jsonb) {
            rename($jsonb, $jsonb.'.later');
        }
        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);
        DB::statement('alter table mediatheque_medias alter column data type text');
        foreach (glob($this->migrations.'/*.later') ?: [] as $jsonb) {
            rename($jsonb, substr($jsonb, 0, -strlen('.later')));
        }

        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);

        $types = $this->jsonColumnTypes();
        $this->assertSame('text', $types['medias.data']);
        $this->assertSame('jsonb', $types['medias.metadata']);

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true]);
    }

    #[Test]
    public function a_progress_that_nothing_measured_becomes_null(): void
    {
        $this->install();
        $this->startFromAnEmptyDatabase();
        foreach (glob($this->migrations.'/*_make_mediatheque_progress_nullable.php') ?: [] as $nullable) {
            rename($nullable, $nullable.'.later');
        }
        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);

        $run = fn (string $uid, string $status, float $progress) => DB::table('mediatheque_pipelines')->insertGetId([
            'uid' => $uid, 'name' => 'video', 'pipelinable_type' => 'media', 'pipelinable_id' => 1, 'transcoder' => 'ffmpeg',
            'definition' => '{}', 'output_source' => 'public', 'path_prefix' => $uid, 'visibility' => 'public', 'status' => $status, 'progress' => $progress,
        ]);
        $running = $run('run_1', 'running', 0.0);
        $run('run_2', 'completed', 1.0);
        foreach ([['h264', 'running', 0.0], ['webm', 'running', 0.4], ['poster', 'completed', 1.0], ['thumbnails', 'failed', 0.0], ['waveform', 'queued', 0.0]] as [$name, $status, $progress]) {
            DB::table('mediatheque_pipelines_jobs')->insert(['pipeline_id' => $running, 'name' => $name, 'status' => $status, 'progress' => $progress]);
        }
        foreach (glob($this->migrations.'/*.later') ?: [] as $nullable) {
            rename($nullable, substr($nullable, 0, -strlen('.later')));
        }

        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);

        $progress = fn (string $table) => DB::table($table)->orderBy('id')->pluck('progress')->map(fn (mixed $value) => is_numeric($value) ? (float) $value : $value)->all();
        $this->assertSame([null, 0.4, 1.0, null, null], $progress('mediatheque_pipelines_jobs'));
        $this->assertSame([null, 1.0], $progress('mediatheque_pipelines'));

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true, '--step' => 1]);
        $this->assertSame([0.0, 0.4, 1.0, 0.0, 0.0], $progress('mediatheque_pipelines_jobs'), 'Rolled back, unknown is 0 again.');

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true]);
    }

    /**
     * @return array<string, string>
     */
    private function jsonColumnTypes(): array
    {
        $types = [];
        foreach (['medias' => ['metadata', 'data', 'transform'], 'pipelines' => ['definition', 'error'], 'pipelines_jobs' => ['error'], 'files' => ['metadata']] as $table => $columns) {
            foreach (Schema::getColumns('mediatheque_'.$table) as $column) {
                if (in_array($column['name'], $columns, true)) {
                    $types[$table.'.'.$column['name']] = $column['type_name'];
                }
            }
        }
        ksort($types);

        return $types;
    }

    #[Test]
    public function index_and_foreign_key_names_fit_in_mysql_and_postgresql_identifiers_with_a_longer_prefix(): void
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
        // SQLite and MySQL keep the names whole, so their runs catch a name
        // that PostgreSQL would truncate silently, and could then make two
        // names collide
        $this->assertSame([], array_values(array_filter($names, fn (string $name) => strlen($name) > 63)), 'MySQL refuses identifiers longer than 64 characters, and PostgreSQL truncates them to 63.');

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
