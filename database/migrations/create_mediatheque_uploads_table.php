<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Presigned multipart uploads (docs/decisions/0006, 0015): each one belongs
 * to the user who created it, and expires.
 */
return new class extends Migration
{
    public function up(): void
    {
        $prefix = $this->prefix();

        Schema::create($prefix.'uploads', function (Blueprint $table) use ($prefix) {
            $table->id();
            $table->string('uid', 64)->unique();
            $table->string('status', 16)->default('pending')->index();
            $table->string('disk', 64);
            $table->string('path', 1024);
            $table->string('provider_upload_id', 1024)->nullable();
            $table->string('filename')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->nullableMorphs('creator');
            $table->foreignId('media_id')->nullable()->constrained($prefix.'medias')->nullOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->prefix().'uploads');
    }

    private function prefix(): string
    {
        $prefix = config('mediatheque.table_prefix', 'mediatheque_');

        return is_string($prefix) ? $prefix : 'mediatheque_';
    }
};
