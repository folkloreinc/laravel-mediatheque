<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The non-destructive edits of a media's image, outside its metadata
 * (docs/decisions/0022): Panneau 5's `MediaTransform`, focal point included.
 * `jsonb` on PostgreSQL, `json` on MySQL, as the other JSON columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table($this->prefix().'medias', function (Blueprint $table) {
            $table->jsonb('transform')->nullable()->after('data');
        });
    }

    public function down(): void
    {
        Schema::table($this->prefix().'medias', function (Blueprint $table) {
            $table->dropColumn('transform');
        });
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
