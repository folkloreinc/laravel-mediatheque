<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMediathequeDocumentsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'documents', function (Blueprint $table) {
            $table->increments('id');
            $table->string('handle')->nullable();
            $table->string('name')->nullable();
            $table->smallInteger('pages')->unsigned()->nullable();
            $table->timestamps();

            $table->index('handle');
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
        Schema::dropIfExists(config('mediatheque.table_prefix').'documents');
    }
}
