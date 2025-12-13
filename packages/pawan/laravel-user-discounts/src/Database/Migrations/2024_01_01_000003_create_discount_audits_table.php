<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->constrained()->cascadeOnDelete();
            $table->enum('action', ['assigned', 'revoked', 'applied', 'failed'])->index();
            $table->decimal('original_amount', 10, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->decimal('final_amount', 10, 2)->nullable();
            $table->json('applied_discounts')->nullable()->comment('Array of all discounts applied in this transaction');
            $table->json('metadata')->nullable()->comment('Additional context like error messages, stack traces, etc');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent()->index();

            // Composite indexes for audit queries
            $table->index(['user_id', 'action', 'created_at'], 'idx_user_action_date');
            $table->index(['discount_id', 'action', 'created_at'], 'idx_discount_action_date');
            $table->index(['action', 'created_at'], 'idx_action_date');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('discount_audits');
    }
};
