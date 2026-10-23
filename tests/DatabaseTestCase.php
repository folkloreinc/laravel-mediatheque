<?php

namespace Folklore\Mediatheque\Tests;

use Folklore\Mediatheque\Console\InstallCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Feature tests on the package's tables, created by the migrations that
 * `mediatheque:install` publishes.
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        // On MySQL, the tables are migrated once for the whole run, and each
        // test runs in a transaction
        if (RefreshDatabaseState::$migrated) {
            return;
        }

        $this->loadMigrationsFrom([...InstallCommand::migrations(), self::applicationMigrations()]);
    }
}
