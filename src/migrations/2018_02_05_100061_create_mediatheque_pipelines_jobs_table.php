<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMediathequePipelinesJobsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'pipelines_jobs', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->integer('pipeline_id')->unsigned();
            $table->longText('definition')->nullable();
            $table->boolean('started')->default(false);
            $table->boolean('ended')->default(false);
            $table->boolean('failed')->default(false);
            $table->text('failed_exception')->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index('name');
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
        Schema::dropIfExists(config('mediatheque.table_prefix').'pipelines_jobs');
    }
}
