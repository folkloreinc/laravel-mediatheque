<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateMediathequeMetadatasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create(config('mediatheque.table_prefix').'metadatas', function (Blueprint $table) {
            $table->increments('id');
            $table->morphs('morphable');
            $table->string('name');
            $table->enum('type', ['string', 'text', 'integer', 'float', 'boolean', 'json']);
            $table->string('value_string')->nullable();
            $table->text('value_text')->nullable();
            $table->integer('value_integer')->nullable();
            $table->float('value_float', 8, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->longText('value_json')->nullable();
            $table->timestamps();

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
        Schema::dropIfExists(config('mediatheque.table_prefix').'metadatas');
    }
}
