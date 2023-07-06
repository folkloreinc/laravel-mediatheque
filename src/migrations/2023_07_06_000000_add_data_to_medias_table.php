<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDataToMediasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table(config('mediatheque.table_prefix').'medias', function (Blueprint $table) {
            $table->json('data')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table(config('mediatheque.table_prefix').'medias', function (Blueprint $table) {
            $table->dropColumn('data');
        });
    }
}
