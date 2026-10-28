<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A progress that nothing measured is null, not 0 (docs/decisions/0023).
 * Renditions and runs that didn't complete with a progress of 0 were never
 * measured: no driver reports exactly 0, so they become null.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['pipelines', 'pipelines_jobs'] as $table) {
            Schema::table($this->prefix().$table, function (Blueprint $table) {
                $table->float('progress')->nullable()->default(null)->change();
            });
        }

        DB::table($this->prefix().'pipelines_jobs')->where('progress', 0)->where('status', '!=', 'completed')->update(['progress' => null]);
        DB::table($this->prefix().'pipelines')->where('progress', 0)->whereIn('status', ['pending', 'running'])->update(['progress' => null]);
    }

    public function down(): void
    {
        foreach (['pipelines', 'pipelines_jobs'] as $table) {
            DB::table($this->prefix().$table)->whereNull('progress')->update(['progress' => 0]);

            Schema::table($this->prefix().$table, function (Blueprint $table) {
                $table->float('progress')->default(0)->change();
            });
        }
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
