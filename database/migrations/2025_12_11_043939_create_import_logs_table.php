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
        Schema::create('import_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('import_type'); // products, users, etc.
            $table->string('filename');
            $table->string('file_hash')->nullable(); // SHA256 to prevent duplicate imports
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'partially_completed'])->default('pending');

            // Summary statistics
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('processed_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('updated_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);

            // Tracking and metadata
            $table->json('error_details')->nullable(); // Store validation errors
            $table->json('configuration')->nullable(); // Import settings/options
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('processing_time_seconds')->nullable()->default(0);

            // User tracking
            $table->unsignedBigInteger('user_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Indexes for reporting and queries
            $table->index(['import_type', 'status', 'created_at']);
            $table->index('file_hash');
            $table->index('user_id');
            $table->index(['status', 'started_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
