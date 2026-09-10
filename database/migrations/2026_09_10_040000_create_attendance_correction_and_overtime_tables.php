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
     * Sprint 12 — Attendance Correction + Overtime.
     * Additive architecture:
     * - Additive column overtime_minutes on attendances table.
     * - New table attendance_correction_requests for immutable correction history and approval workflow.
     * - New table overtime_requests for auditable overtime requests and approval workflow.
     */
    public function up(): void
    {
        // ----------------------------------------------------------------
        // Pre-check: capture baseline row counts
        // ----------------------------------------------------------------
        $trackedTables = [
            'employees',
            'attendances',
            'access_logs',
            'attendance_evidences',
            'field_attendance_evidences',
            'attendance_requests',
        ];

        $beforeCounts = [];
        foreach ($trackedTables as $table) {
            if (Schema::hasTable($table)) {
                $beforeCounts[$table] = DB::table($table)->count();
            }
        }

        // 1. Additive column overtime_minutes on attendances
        if (Schema::hasTable('attendances') && !Schema::hasColumn('attendances', 'overtime_minutes')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->integer('overtime_minutes')->default(0)->after('effective_work_minutes');
            });
        }

        // 2. Table: attendance_correction_requests
        if (!Schema::hasTable('attendance_correction_requests')) {
            Schema::create('attendance_correction_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
                $table->date('correction_date')->index();
                $table->string('request_type', 32)->index(); // CHECK_IN, CHECK_OUT, CHECK_IN_AND_OUT, STATUS, ATTENDANCE_TYPE

                // Original snapshot (immutable history)
                $table->timestamp('original_check_in')->nullable();
                $table->timestamp('original_check_out')->nullable();
                $table->string('original_status', 32)->nullable();
                $table->string('original_attendance_type', 32)->nullable();

                // Requested changes
                $table->timestamp('requested_check_in')->nullable();
                $table->timestamp('requested_check_out')->nullable();
                $table->string('requested_status', 32)->nullable();
                $table->string('requested_attendance_type', 32)->nullable();

                // Corrected outcome snapshot (applied on approval)
                $table->timestamp('corrected_check_in')->nullable();
                $table->timestamp('corrected_check_out')->nullable();
                $table->string('corrected_status', 32)->nullable();
                $table->string('corrected_attendance_type', 32)->nullable();

                $table->text('reason');
                $table->text('evidence_note')->nullable();
                $table->string('attachment_path', 512)->nullable();
                $table->string('correction_source', 32)->default('EMPLOYEE_REQUEST'); // EMPLOYEE_REQUEST, HR_MANUAL, SUPERVISOR_APPROVED

                $table->string('status', 32)->default('SUBMITTED')->index(); // DRAFT, SUBMITTED, APPROVED, REJECTED, CANCELLED

                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->text('rejection_reason')->nullable();

                $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamps();

                $table->index(['employee_id', 'correction_date'], 'idx_corr_emp_date');
                $table->index(['employee_id', 'status'], 'idx_corr_emp_status');
            });
        }

        // 3. Table: overtime_requests
        if (!Schema::hasTable('overtime_requests')) {
            Schema::create('overtime_requests', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
                $table->date('overtime_date')->index();

                $table->time('requested_start');
                $table->time('requested_end');
                $table->integer('requested_minutes')->default(0);
                $table->integer('approved_minutes')->default(0);

                $table->text('reason');
                $table->string('project_task_reference', 255)->nullable();
                $table->string('attachment_path', 512)->nullable();

                $table->string('status', 32)->default('SUBMITTED')->index(); // DRAFT, SUBMITTED, APPROVED, REJECTED, CANCELLED

                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->text('rejection_reason')->nullable();

                $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('admins')->nullOnDelete();
                $table->timestamps();

                $table->index(['employee_id', 'overtime_date'], 'idx_ot_emp_date');
                $table->index(['employee_id', 'status'], 'idx_ot_emp_status');
            });
        }

        // ----------------------------------------------------------------
        // Post-check: ensure zero data loss on existing tables
        // ----------------------------------------------------------------
        foreach ($beforeCounts as $table => $before) {
            $after = DB::table($table)->count();
            if ($after !== $before) {
                throw new \RuntimeException(
                    "Sprint 12 migration row-count invariant violated: {$table} had {$before} rows before, has {$after} after."
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('overtime_requests');
        Schema::dropIfExists('attendance_correction_requests');

        if (Schema::hasTable('attendances') && Schema::hasColumn('attendances', 'overtime_minutes')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->dropColumn('overtime_minutes');
            });
        }
    }
};
