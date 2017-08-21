<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMediathequeAudiosTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'audios', function (Blueprint $table) {
            $table->increments('id');
            $table->string('handle')->nullable();
            $table->string('name')->nullable();
            $table->float('duration', 8, 4)->unsigned()->default(0);
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
        Schema::dropIfExists(config('mediatheque.table_prefix').'audios');
    }
}
