<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On PostgreSQL, the JSON columns of the package's tables become `jsonb`,
 * which can be compared, indexed and queried by path. The other databases
 * keep their `json` columns: this migration does nothing there. A column that
 * is not `json` (one an application changed, or a 1.x column the upgrade
 * hasn't converted yet) is left as it is.
 */
return new class extends Migration
{
    /**
     * The JSON columns of each table, and whether they are nullable.
     *
     * @var array<string, array<string, bool>>
     */
    private const COLUMNS = [
        'medias' => ['metadata' => true, 'data' => true, 'transform' => true],
        'pipelines' => ['definition' => false, 'error' => true],
        'pipelines_jobs' => ['error' => true],
        'files' => ['metadata' => true],
    ];

    public function up(): void
    {
        $this->convert('json', 'jsonb');
    }

    public function down(): void
    {
        $this->convert('jsonb', 'json');
    }

    private function convert(string $from, string $to): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::COLUMNS as $name => $columns) {
            $table = $this->prefix().$name;
            $types = array_column(Schema::getColumns($table), 'type_name', 'name');
            $changed = array_filter($columns, fn (string $column) => ($types[$column] ?? null) === $from, ARRAY_FILTER_USE_KEY);

            if ($changed === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $table) use ($changed, $to) {
                foreach ($changed as $column => $nullable) {
                    $definition = $to === 'jsonb' ? $table->jsonb($column) : $table->json($column);
                    $definition->nullable($nullable)->using(sprintf('"%s"::%s', $column, $to))->change();
                }
            });
        }
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
