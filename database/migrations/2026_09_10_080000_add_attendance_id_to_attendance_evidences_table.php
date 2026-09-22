<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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
        Schema::table('attendance_evidences', function (Blueprint $table) {
            $table->dropForeign(['attendance_id']);
            $table->dropColumn('attendance_id');
        });
    }
};
