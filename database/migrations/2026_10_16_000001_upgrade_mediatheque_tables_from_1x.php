<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the columns of laravel-mediatheque 2 to the tables of 1.x
 * (docs/decisions/0002, docs/upgrade.md). `mediatheque:install --upgrade`
 * publishes it instead of `create_mediatheque_tables`.
 *
 * It renames no table and converts no data: `mediatheque:upgrade` fills the
 * new columns, removes orphan rows and then adds the foreign keys that
 * existing rows could break. A column that is there already is left as it
 * is, so a table that an application changed doesn't stop it.
 *
 * Columns that every row of a new install has (`uid`, a run's `transcoder`)
 * are nullable here: existing rows get them from `mediatheque:upgrade`, and
 * the models set them on every new row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = $this->prefix();
        $medias = $prefix.'medias';
        $files = $prefix.'files';
        $pipelines = $prefix.'pipelines';
        $jobs = $prefix.'pipelines_jobs';

        $this->addColumns($medias, function (Blueprint $table, array $missing) use ($medias) {
            if (isset($missing['uid'])) {
                $table->string('uid', 64)->nullable()->unique();
            }
            if (isset($missing['status'])) {
                $table->string('status', 16)->default('pending')->index();
            }
            if (isset($missing['visibility'])) {
                $table->string('visibility', 16)->default('public');
            }
            if (isset($missing['metadata'])) {
                $table->json('metadata')->nullable();
            }
            if (isset($missing['data'])) {
                $table->json('data')->nullable();
            }
            if (isset($missing['derived_from_id'])) {
                $this->idColumn($table, 'derived_from_id', $medias)->nullable();
                $table->foreign('derived_from_id')->references('id')->on($medias)->nullOnDelete();
            }
            if (isset($missing['deleted_at'])) {
                $table->softDeletes();
            }
        }, ['uid', 'status', 'visibility', 'metadata', 'data', 'derived_from_id', 'deleted_at']);

        $this->addColumns($jobs, function (Blueprint $table, array $missing) {
            if (isset($missing['status'])) {
                $table->string('status', 16)->default('queued')->index();
            }
            if (isset($missing['attempts'])) {
                $table->unsignedInteger('attempts')->default(0);
            }
            if (isset($missing['error'])) {
                $table->json('error')->nullable();
            }
            if (isset($missing['reason'])) {
                $table->text('reason')->nullable();
            }
        }, ['status', 'attempts', 'error', 'reason']);

        $this->addColumns($pipelines, function (Blueprint $table, array $missing) {
            if (isset($missing['uid'])) {
                $table->string('uid', 64)->nullable()->unique();
            }
            if (isset($missing['transcoder'])) {
                $table->string('transcoder', 64)->nullable();
            }
            if (isset($missing['external_id'])) {
                $table->string('external_id')->nullable();
            }
            if (isset($missing['output_source'])) {
                $table->string('output_source', 64)->nullable();
            }
            if (isset($missing['path_prefix'])) {
                $table->string('path_prefix')->nullable();
            }
            if (isset($missing['visibility'])) {
                $table->string('visibility', 16)->nullable();
            }
            if (isset($missing['status'])) {
                $table->string('status', 16)->default('pending')->index();
            }
            if (isset($missing['attempts'])) {
                $table->unsignedInteger('attempts')->default(0);
            }
            if (isset($missing['error'])) {
                $table->json('error')->nullable();
            }
            if (isset($missing['transcoder'], $missing['external_id'])) {
                $table->index(['transcoder', 'external_id']);
            }
        }, ['uid', 'transcoder', 'external_id', 'output_source', 'path_prefix', 'visibility', 'status', 'attempts', 'error']);

        $this->addColumns($files, function (Blueprint $table, array $missing) use ($medias, $jobs) {
            if (isset($missing['media_id'])) {
                // Filled from files_pivot by mediatheque:upgrade, which then
                // adds the foreign key. A file that 1.x attached only to
                // other models keeps none.
                $this->idColumn($table, 'media_id', $medias)->nullable();
            }
            if (isset($missing['index'])) {
                $table->unsignedInteger('index')->nullable();
            }
            if (isset($missing['role'])) {
                $table->string('role', 64)->nullable();
            }
            if (isset($missing['checksum'])) {
                $table->char('checksum', 64)->nullable();
            }
            if (isset($missing['width'])) {
                $table->unsignedInteger('width')->nullable();
            }
            if (isset($missing['height'])) {
                $table->unsignedInteger('height')->nullable();
            }
            if (isset($missing['duration'])) {
                $table->double('duration')->nullable();
            }
            if (isset($missing['bitrate'])) {
                $table->unsignedBigInteger('bitrate')->nullable();
            }
            if (isset($missing['parts_count'])) {
                $table->unsignedInteger('parts_count')->nullable();
            }
            if (isset($missing['metadata'])) {
                $table->json('metadata')->nullable();
            }
            if (isset($missing['definition_hash'])) {
                $table->char('definition_hash', 64)->nullable();
            }
            if (isset($missing['pipeline_job_id'])) {
                $this->idColumn($table, 'pipeline_job_id', $jobs)->nullable();
                $table->foreign('pipeline_job_id')->references('id')->on($jobs)->nullOnDelete();
            }
            if (isset($missing['provider'])) {
                $table->string('provider', 64)->nullable();
            }
            if (isset($missing['provider_ref'])) {
                $table->string('provider_ref')->nullable();
            }
            if (isset($missing['media_id'], $missing['index'])) {
                $table->index(['media_id', 'handle', 'index']);
            }
        }, ['media_id', 'index', 'role', 'checksum', 'width', 'height', 'duration', 'bitrate', 'parts_count', 'metadata', 'definition_hash', 'pipeline_job_id', 'provider', 'provider_ref']);

        // 1.x limited mimes to 50 characters and sizes to 32 bits. Widening
        // them loses nothing, so down() leaves them wide.
        Schema::table($files, function (Blueprint $table) {
            $table->string('mime')->nullable()->change();
            $table->unsignedBigInteger('size')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        $prefix = $this->prefix();

        Schema::table($prefix.'files', function (Blueprint $table) {
            $table->dropForeign(['pipeline_job_id']);
            $table->dropIndex(['media_id', 'handle', 'index']);
            $table->dropColumn(['media_id', 'index', 'role', 'checksum', 'width', 'height', 'duration', 'bitrate', 'parts_count', 'metadata', 'definition_hash', 'pipeline_job_id', 'provider', 'provider_ref']);
        });

        Schema::table($prefix.'pipelines', function (Blueprint $table) {
            $table->dropUnique(['uid']);
            $table->dropIndex(['status']);
            $table->dropIndex(['transcoder', 'external_id']);
            $table->dropColumn(['uid', 'transcoder', 'external_id', 'output_source', 'path_prefix', 'visibility', 'status', 'attempts', 'error']);
        });

        Schema::table($prefix.'pipelines_jobs', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn(['status', 'attempts', 'error', 'reason']);
        });

        Schema::table($prefix.'medias', function (Blueprint $table) {
            $table->dropForeign(['derived_from_id']);
            $table->dropUnique(['uid']);
            $table->dropIndex(['status']);
            $table->dropColumn(['uid', 'status', 'visibility', 'metadata', 'derived_from_id', 'deleted_at']);
        });
    }

    /**
     * Add the columns of a table that it doesn't have yet.
     *
     * @param  Closure(Blueprint, array<string, true>): void  $callback
     * @param  list<string>  $columns
     */
    private function addColumns(string $table, Closure $callback, array $columns): void
    {
        $missing = array_fill_keys(array_values(array_filter($columns, fn (string $column) => ! Schema::hasColumn($table, $column))), true);

        if ($missing !== []) {
            Schema::table($table, fn (Blueprint $blueprint) => $callback($blueprint, $missing));
        }
    }

    /**
     * A column that refers to the `id` of a table, of the same type: 1.x
     * tables have 32-bit ids (`increments`), and MySQL refuses a foreign key
     * between columns of different types.
     */
    private function idColumn(Blueprint $table, string $column, string $referenced): ColumnDefinition
    {
        $types = array_column(Schema::getColumns($referenced), 'type_name', 'name');

        return in_array(strtolower((string) ($types['id'] ?? '')), ['bigint', 'int8'], true)
            ? $table->unsignedBigInteger($column)
            : $table->unsignedInteger($column);
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
