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
        // SQLite doesn't support dropping individual foreign keys in traditional way.
        // For PostgreSQL and MySQL, drop the foreign key constraint first.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('attendance_evidences', function (Blueprint $table) {
                $table->dropForeign(['attendance_id']);
            });
        }

        Schema::table('attendance_evidences', function (Blueprint $table) {
            $table->dropColumn('attendance_id');
        });
    }
};