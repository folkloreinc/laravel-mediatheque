<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMediathequeAudiosPivotTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'audios_pivot', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('audio_id')->unsigned();
            $table->morphs('morphable');
            $table->string('handle')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index('audio_id');
            $table->index('handle');
            $table->index('order');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(config('mediatheque.table_prefix').'audios_pivot');
    }
}
