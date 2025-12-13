<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_chunks', function (Blueprint $table) {
            $table->id();
            $table->string('upload_session_id')->index();
            $table->integer('chunk_index');
            $table->string('chunk_path');
            $table->bigInteger('chunk_size');
            $table->string('checksum')->nullable();
            $table->timestamps();

            $table->unique(['upload_session_id', 'chunk_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_chunks');
    }
};
