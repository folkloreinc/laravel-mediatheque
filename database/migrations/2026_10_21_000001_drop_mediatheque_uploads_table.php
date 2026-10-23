<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Direct uploads left the package (docs/decisions/0018): the application's
 * own routes, such as Panneau's, take uploads, and the package ingests their
 * files from the disk. Drops the table that earlier alphas created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists($this->prefix().'uploads');
    }

    public function down(): void
    {
        // Nothing to restore: uploads were temporary, and the package no longer reads them
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
