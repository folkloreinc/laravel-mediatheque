<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateMediathequeFilesMetadata extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table(config('mediatheque.table_prefix').'files', function (Blueprint $table) {
            $table->longText('metadata')->after('size')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table(config('mediatheque.table_prefix').'files', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
}
