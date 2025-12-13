<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->integer('usage_count')->default(0);
            $table->timestamp('assigned_at')->useCurrent();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revocation_reason')->nullable();
            $table->timestamps();

            // Unique constraint to prevent duplicate assignments
            $table->unique(['user_id', 'discount_id'], 'idx_user_discount_unique');

            // Composite indexes for common queries
            $table->index(['user_id', 'revoked_at'], 'idx_user_active_discounts');
            $table->index(['discount_id', 'usage_count'], 'idx_discount_usage');
            $table->index(['assigned_at'], 'idx_assigned_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('user_discounts');
    }
};
