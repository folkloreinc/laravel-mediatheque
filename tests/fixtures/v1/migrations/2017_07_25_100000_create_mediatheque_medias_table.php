<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMediathequeMediasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'medias', function (Blueprint $table) {
            $table->increments('id');
            $table->string('type');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->index('type');
            $table->index('name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(config('mediatheque.table_prefix').'medias');
    }
}
