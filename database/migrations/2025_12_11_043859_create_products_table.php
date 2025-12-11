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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->uuid('primary_image_id')->nullable();
            $table->enum('status', ['active', 'inactive', 'discontinued'])->default('active');
            $table->json('metadata')->nullable(); // Additional flexible data
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('sku');
            $table->index(['status', 'created_at']);
            $table->index('primary_image_id');
        });

        // Add foreign key after images table exists
        Schema::table('products', function (Blueprint $table) {
            $table->foreign('primary_image_id')
                  ->references('id')
                  ->on('images')
                  ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['primary_image_id']);
        });

        Schema::dropIfExists('products');
    }
};
