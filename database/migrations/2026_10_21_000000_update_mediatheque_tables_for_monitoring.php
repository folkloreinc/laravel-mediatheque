<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What monitoring needs (docs/decisions/0017): when each rendition starts
 * waiting in the queue, and indexes for queries over a period.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = $this->prefix();

        Schema::table($prefix.'pipelines', function (Blueprint $table) {
            $table->index('created_at');
            $table->index('ended_at');
        });

        Schema::table($prefix.'pipelines_jobs', function (Blueprint $table) {
            $table->timestamp('queued_at')->nullable()->after('reason');

            $table->index('created_at');
            $table->index('ended_at');
        });
    }

    public function down(): void
    {
        $prefix = $this->prefix();

        Schema::table($prefix.'pipelines_jobs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['ended_at']);
            $table->dropColumn('queued_at');
        });

        Schema::table($prefix.'pipelines', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['ended_at']);
        });
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
