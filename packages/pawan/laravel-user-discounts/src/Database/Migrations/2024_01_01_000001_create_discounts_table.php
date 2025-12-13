<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique()->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['percentage', 'fixed'])->default('percentage');
            $table->decimal('value', 10, 2);
            $table->integer('priority')->default(0)->comment('Lower number = higher priority');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('starts_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->integer('max_uses_per_user')->nullable()->comment('Null = unlimited');
            $table->integer('max_total_uses')->nullable()->comment('Null = unlimited');
            $table->integer('total_uses')->default(0);
            $table->json('metadata')->nullable()->comment('Additional discount rules/conditions');
            $table->timestamps();
            $table->softDeletes();

            // Composite indexes for common queries
            $table->index(['is_active', 'starts_at', 'expires_at'], 'idx_active_date_range');
            $table->index(['type', 'is_active'], 'idx_type_active');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('discounts');
    }
};
