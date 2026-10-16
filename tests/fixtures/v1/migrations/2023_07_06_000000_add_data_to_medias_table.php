<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddDataToMediasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (! Schema::hasColumn(config('mediatheque.table_prefix').'medias', 'data')) {
            Schema::table(config('mediatheque.table_prefix').'medias', function (Blueprint $table) {
                $table->json('data')->nullable()->after('name');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn(config('mediatheque.table_prefix').'medias', 'data')) {
            Schema::table(config('mediatheque.table_prefix').'medias', function (Blueprint $table) {
                $table->dropColumn('data');
            });
        }
    }
}
