<?php

namespace Folklore\Mediatheque\Tests;

use Folklore\Mediatheque\Console\InstallCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Feature tests on the package's tables, created by the migrations that
 * `mediatheque:install` publishes.
 */
abstract class DatabaseTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(InstallCommand::migrations());
    }
}
