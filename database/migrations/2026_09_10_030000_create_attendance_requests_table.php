<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Invariants:
     *  - Existing tables (employees, admins, doors, attendances, etc.) preserve row count.
     *  - Additive table attendance_requests for WFH, Leave, Permission, and Sick requests.
     *  - Strict foreign keys, indexing, and deterministic lifecycle statuses.
     */
    public function up(): void
    {
        // 0. Row-count pre-check on existing tables
        $tablesToCheck = ['employees', 'access_logs', 'admins', 'attendances', 'doors', 'work_calendars', 'attendance_evidences', 'field_attendance_evidences'];
        $beforeCounts = [];
        foreach ($tablesToCheck as $tbl) {
            if (Schema::hasTable($tbl)) {
                $beforeCounts[$tbl] = DB::table($tbl)->count();
            }
        }

        // 1. Create attendance_requests table
        if (!Schema::hasTable('attendance_requests')) {
            Schema::create('attendance_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->string('request_type'); // WFH, LEAVE, PERMISSION, SICK
                $table->string('status')->default('SUBMITTED'); // DRAFT, SUBMITTED, APPROVED, REJECTED, CANCELLED
                $table->string('category')->nullable(); // ANNUAL, UNPAID, LATE_ARRIVAL, EARLY_DEPARTURE, FULL_DAY, etc.
                $table->date('start_date');
                $table->date('end_date');
                $table->string('start_time', 10)->nullable(); // e.g. '08:00'
                $table->string('end_time', 10)->nullable();   // e.g. '12:00'
                $table->text('reason');
                $table->string('attachment_path')->nullable();
                $table->string('attachment_mime')->nullable();
                $table->unsignedInteger('attachment_size_bytes')->nullable();
                $table->string('attachment_original_name')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->unsignedBigInteger('cancelled_by')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['employee_id', 'status'], 'idx_att_req_emp_status');
                $table->index(['start_date', 'end_date'], 'idx_att_req_dates');
                $table->index(['request_type', 'status'], 'idx_att_req_type_status');
                $table->index('status', 'idx_att_req_status');
            });
        }

        // 2. Post-check: existing tables must not lose rows
        foreach ($beforeCounts as $tbl => $before) {
            $after = DB::table($tbl)->count();
            if ($after < $before) {
                throw new \RuntimeException(
                    "Migration safety violation: table '{$tbl}' lost rows ({$before} -> {$after})"
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_requests');
    }
};
