<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMediathequeFilesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'files', function (Blueprint $table) {
            $table->increments('id');
            $table->string('handle')->nullable();
            $table->string('source')->nullable();
            $table->string('name')->nullable();
            $table->string('path')->nullable();
            $table->string('type', 50)->nullable();
            $table->string('mime', 50)->nullable();
            $table->integer('size')->unsigned()->default(0);
            $table->timestamps();

            $table->index('handle');
            $table->index('name');
            $table->index('source');
            $table->index('path');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(config('mediatheque.table_prefix').'files');
    }
}
