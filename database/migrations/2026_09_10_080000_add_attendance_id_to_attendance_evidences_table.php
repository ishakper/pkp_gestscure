<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_evidences', function (Blueprint $table) {
            $table->foreignId('attendance_id')->nullable()->after('access_log_id')->constrained('attendances')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // SQLite doesn't support dropping individual foreign keys without recreating the table.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA foreign_keys=OFF');
        }

        try {
            Schema::table('attendance_evidences', function (Blueprint $table) {
                $table->dropColumn('attendance_id');
            });
        } finally {
            if (DB::getDriverName() === 'sqlite') {
                DB::statement('PRAGMA foreign_keys=ON');
            }
        }
    }
};