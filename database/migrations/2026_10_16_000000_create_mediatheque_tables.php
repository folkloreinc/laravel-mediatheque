<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables of a new install of laravel-mediatheque 2 (docs/decisions/0015).
 * They keep their 1.x names; the upgrade from 1.x converts 1.x tables to the
 * same columns. Add your own columns (a tenant id, for example) here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = $this->prefix();

        Schema::create($prefix.'medias', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('uid', 64)->unique();
            $table->string('type', 64)->index();
            $table->string('name')->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->string('visibility', 16)->default('public');
            $table->json('metadata')->nullable();
            $table->json('data')->nullable();
            $table->foreignId('derived_from_id')->nullable()->constrained($prefix.'medias')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create($prefix.'pipelines', function (Blueprint $table) {
            $table->id();
            $table->string('uid', 64)->unique();
            $table->string('name', 64);
            $table->morphs('pipelinable');
            $table->string('transcoder', 64);
            $table->string('external_id')->nullable();
            $table->json('definition');
            $table->string('output_source', 64);
            $table->string('path_prefix');
            $table->string('visibility', 16);
            $table->string('status', 16)->default('pending')->index();
            $table->float('progress')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->json('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['transcoder', 'external_id']);
        });

        Schema::create($prefix.'pipelines_jobs', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('pipeline_id')->constrained($prefix.'pipelines')->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('status', 16)->default('queued')->index();
            $table->float('progress')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->json('error')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['pipeline_id', 'name']);
        });

        Schema::create($prefix.'files', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->foreignId('media_id')->constrained($prefix.'medias')->cascadeOnDelete();
            $table->string('handle', 64);
            $table->unsignedInteger('index')->nullable();
            $table->string('role', 64)->nullable();
            $table->string('source', 64)->nullable();
            $table->string('path', 1024)->nullable();
            $table->string('name')->nullable();
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->char('checksum', 64)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->double('duration')->nullable();
            $table->unsignedBigInteger('bitrate')->nullable();
            $table->unsignedInteger('parts_count')->nullable();
            $table->json('metadata')->nullable();
            $table->char('definition_hash', 64)->nullable();
            $table->foreignId('pipeline_job_id')->nullable()->constrained($prefix.'pipelines_jobs')->nullOnDelete();
            $table->string('provider', 64)->nullable();
            $table->string('provider_ref')->nullable();
            $table->timestamps();

            $table->index(['media_id', 'handle', 'index']);
        });
    }

    public function down(): void
    {
        $prefix = $this->prefix();

        Schema::dropIfExists($prefix.'files');
        Schema::dropIfExists($prefix.'pipelines_jobs');
        Schema::dropIfExists($prefix.'pipelines');
        Schema::dropIfExists($prefix.'medias');
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
