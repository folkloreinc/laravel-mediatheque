<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The media attached to application models by handle, through the HasMedias
 * trait: also the usage graph (docs/decisions/0020). A project upgraded from
 * 1.x already has this table, under the same name: it is left as it is, and
 * the upgrade adds its foreign key once orphan rows are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = $this->prefix();

        if (Schema::hasTable($prefix.'medias_pivot')) {
            return;
        }

        Schema::create($prefix.'medias_pivot', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('media_id')->constrained($prefix.'medias')->cascadeOnDelete();
            $table->morphs('morphable', $prefix.'medias_pivot_morphable_index');
            $table->string('handle', 64)->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            // Named: the generated name is longer than the 64 characters
            // MySQL allows
            $table->unique(['morphable_type', 'morphable_id', 'handle', 'media_id'], $prefix.'medias_pivot_usage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix().'medias_pivot');
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
