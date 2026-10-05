<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('door_assignments', function (Blueprint $table) {
            $table->string('failed_step', 32)->nullable()->after('last_sync_error');
            $table->string('last_sync_status_code', 64)->nullable()->after('failed_step');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('assignment_id')->nullable()->after('subject_id');
            $table->unsignedBigInteger('employee_id')->nullable()->after('assignment_id');
            $table->unsignedBigInteger('door_id')->nullable()->after('employee_id');
            $table->string('failed_step', 32)->nullable()->after('door_id');
            $table->string('status_code', 64)->nullable()->after('failed_step');
            $table->text('error')->nullable()->after('status_code');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropColumn(['assignment_id', 'employee_id', 'door_id', 'failed_step', 'status_code', 'error']);
        });

        Schema::table('door_assignments', function (Blueprint $table) {
            $table->dropColumn(['failed_step', 'last_sync_status_code']);
        });
    }
};
