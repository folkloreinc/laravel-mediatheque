<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMediathequeFontsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'fonts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('handle')->nullable();
            $table->string('name')->nullable();
            $table->string('family_name')->nullable();
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
        Schema::dropIfExists(config('mediatheque.table_prefix').'fonts');
    }
}
