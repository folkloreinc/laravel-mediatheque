<?php

namespace Folklore\Mediatheque\Tests\Feature;

use Folklore\Mediatheque\Tests\TestCase;
use Illuminate\Support\Facades\Schema;

class MigrationsTest extends TestCase
{
    public function test_migrations_use_the_table_prefix()
    {
        config()->set('mediatheque.table_prefix', 'custom_');

        $this->artisan('migrate', ['--database' => 'testbench'])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('custom_medias', 'data'));

        // Roll back the last migration (add_data_to_medias_table), then migrate again
        $this->artisan('migrate:rollback', ['--database' => 'testbench', '--step' => 1])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('custom_medias'));
        $this->assertFalse(Schema::hasColumn('custom_medias', 'data'));

        $this->artisan('migrate', ['--database' => 'testbench'])->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('custom_medias', 'data'));
    }
}
