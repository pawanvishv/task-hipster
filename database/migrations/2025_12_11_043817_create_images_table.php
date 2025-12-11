<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('upload_id');
            $table->string('variant'); // original, thumbnail_256, medium_512, large_1024
            $table->string('path');
            $table->string('disk')->default('public');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_type');
            $table->timestamps();
            $table->softDeletes();

            // Foreign key with cascade
            $table->foreign('upload_id')
                  ->references('id')
                  ->on('uploads')
                  ->onDelete('cascade');

            // Composite index for efficient variant lookups
            $table->index(['upload_id', 'variant']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('images');
    }
};
