<?php

namespace Folklore\Mediatheque\Tests\Feature\Console;

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
        $this->assertCount(1, $this->published());
        $this->assertMatchesRegularExpression('/^\d{4}_\d{2}_\d{2}_\d{6}_create_mediatheque_tables\.php$/', $this->published()[0]);
    }

    #[Test]
    public function running_it_again_publishes_nothing_twice(): void
    {
        $this->install();
        file_put_contents(config_path('mediatheque.php'), "<?php\n\nreturn ['source' => 'local'];\n");

        $this->install();

        $this->assertCount(1, $this->published());
        $this->assertStringContainsString("'local'", (string) file_get_contents(config_path('mediatheque.php')));
    }

    #[Test]
    public function the_published_migration_creates_and_drops_the_tables(): void
    {
        $this->install();

        Artisan::call('migrate', ['--path' => $this->migrations, '--realpath' => true]);

        foreach (['medias', 'files', 'pipelines', 'pipelines_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable('mediatheque_'.$table), $table);
        }
        $this->assertTrue(Schema::hasColumns('mediatheque_files', ['media_id', 'handle', 'index', 'role', 'size', 'checksum', 'definition_hash']));

        Artisan::call('migrate:rollback', ['--path' => $this->migrations, '--realpath' => true]);

        $this->assertFalse(Schema::hasTable('mediatheque_medias'));
    }

    #[Test]
    public function the_package_does_not_load_its_migrations(): void
    {
        $package = (string) realpath(__DIR__.'/../../../database/migrations');

        $this->assertNotContains($package, array_map(fn (string $path) => realpath($path), app()->make('migrator')->paths()));
    }
}
