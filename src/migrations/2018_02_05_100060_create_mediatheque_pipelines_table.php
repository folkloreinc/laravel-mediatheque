<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMediathequePipelinesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'pipelines', function (Blueprint $table) {
            $table->increments('id');
            $table->string('handle')->nullable();
            $table->morphs('pipelinable');
            $table->boolean('started')->default(false);
            $table->boolean('ended')->default(false);
            $table->boolean('failed')->default(false);
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index('handle');
            $table->index(['started', 'ended']);
            $table->index('failed');
            $table->index('started_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists(config('mediatheque.table_prefix').'pipelines');
    }
}
