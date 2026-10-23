<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The application's own tables that the tests use: users, and models that
 * attach media. They are migrated with the package's tables, so a test never
 * creates a table: on MySQL, that would commit the test's transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->boolean('can_upload')->default(true);
            $table->unsignedBigInteger('space_id')->nullable();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('title');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('users');
    }
};
