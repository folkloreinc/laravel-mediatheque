<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The 1.x schema and config are kept as fixtures for the upgrade and
 * compatibility tests. This test keeps them loadable.
 */
class V1FixturesTest extends TestCase
{
    #[Test]
    public function the_1x_migrations_create_the_1x_schema(): void
    {
        $this->startFromAnEmptyDatabase();
        $this->loadMigrationsFrom(self::fixturePath('v1/migrations'));

        foreach (['medias', 'metadatas', 'medias_pivot', 'files', 'files_pivot', 'pipelines', 'pipelines_jobs'] as $table) {
            $this->assertTrue(Schema::hasTable('mediatheque_'.$table), "Missing table mediatheque_{$table}");
        }

        $this->assertTrue(Schema::hasColumn('mediatheque_medias', 'data'));
    }

    #[Test]
    public function the_1x_config_is_readable(): void
    {
        $config = require self::fixturePath('v1/config.php');

        $this->assertIsArray($config);
        $this->assertSame('mediatheque_', $config['table_prefix']);
        $this->assertArrayHasKey('pipelines', $config);
        $this->assertArrayHasKey('types', $config);
    }
}
