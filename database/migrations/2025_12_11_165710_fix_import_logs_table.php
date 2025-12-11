<?php
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            // Rename import_type to type if it exists
            if (Schema::hasColumn('import_logs', 'import_type')) {
                $table->renameColumn('import_type', 'type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            if (Schema::hasColumn('import_logs', 'type')) {
                $table->renameColumn('type', 'import_type');
            }
        });
    }
};
