<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Sprint 8: Work Calendar + Attendance Core
     *
     * Tables created:
     *  - work_calendars     (named work-schedule templates, e.g. "Standard 8-17")
     *  - work_schedule_days (per-day-of-week shift definition inside a calendar)
     *  - public_holidays    (date-level holiday overrides, building-scoped)
     *  - employee_calendar_assignments (which calendar applies to which employee)
     *  - attendances        (one row per employee per calendar-date; daily state)
     *
     * SECURITY / INVARIANT NOTES:
     *  - AccessLog is IMMUTABLE physical-device data. This sprint does NOT touch it.
     *  - All business rules (late tolerance, work hours) live in work_calendars, NOT hardcoded.
     *  - Attendance status is computed by AttendanceProcessor, stored as an enum string.
     */
    public function up(): void
    {
        // ----------------------------------------------------------------
        // 0. Row-count pre-check on tables we will NOT modify (invariant)
        // ----------------------------------------------------------------
        $beforeCounts = [];
        foreach (['employees', 'access_logs', 'admins'] as $tbl) {
            if (Schema::hasTable($tbl)) {
                $beforeCounts[$tbl] = DB::table($tbl)->count();
            }
        }

        // ----------------------------------------------------------------
        // 1. work_calendars — named schedule templates
        // ----------------------------------------------------------------
        Schema::create('work_calendars', function (Blueprint $table) {
            $table->id();
            $table->string('name');                                  // e.g. "Standard 08-17"
            $table->string('code')->unique();                        // e.g. "STD_0817"
            $table->text('description')->nullable();
            $table->unsignedBigInteger('building_id')->nullable();   // null = company-wide
            $table->integer('late_tolerance_minutes')->default(30);  // config-driven, NOT hardcoded
            $table->boolean('is_default')->default(false);           // one default per building
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // ----------------------------------------------------------------
        // 2. work_schedule_days — per-weekday shift definition
        //    day_of_week: 0=Sunday … 6=Saturday
        // ----------------------------------------------------------------
        Schema::create('work_schedule_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_calendar_id')->constrained('work_calendars')->cascadeOnDelete();
            $table->tinyInteger('day_of_week');                      // 0–6
            $table->boolean('is_working_day')->default(true);
            $table->time('check_in_start')->nullable();              // grace window start
            $table->time('check_in_end')->nullable();                // must be present by this time
            $table->time('check_out_start')->nullable();             // earliest acceptable checkout
            $table->time('work_start')->nullable();                  // nominal start (e.g. 08:00)
            $table->time('work_end')->nullable();                    // nominal end   (e.g. 17:00)
            $table->timestamps();

            $table->unique(['work_calendar_id', 'day_of_week'], 'uq_calendar_dow');
        });

        // ----------------------------------------------------------------
        // 3. public_holidays — date-level holiday overrides
        // ----------------------------------------------------------------
        Schema::create('public_holidays', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('building_id')->nullable();   // null = company-wide
            $table->date('holiday_date');
            $table->string('name');
            $table->string('type')->default('NATIONAL');             // NATIONAL | COMPANY | REGIONAL
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->unique(['building_id', 'holiday_date'], 'uq_holiday_bld_date');
        });

        // ----------------------------------------------------------------
        // 4. employee_calendar_assignments — which calendar applies to whom
        // ----------------------------------------------------------------
        Schema::create('employee_calendar_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('work_calendar_id')->constrained('work_calendars')->cascadeOnDelete();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();             // null = indefinite
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'effective_from'], 'idx_eca_emp_eff');
        });

        // ----------------------------------------------------------------
        // 5. attendances — one row per employee per calendar date
        //
        //    Status values (computed by AttendanceProcessor):
        //      PRESENT   — clocked-in on time
        //      LATE      — clocked-in after work_start + late_tolerance_minutes
        //      ABSENT    — no clock-in, working day
        //      OFF       — scheduled day off (weekend or public holiday)
        //      LEAVE     — will be linked to leave module (Sprint 9-12), stub for now
        //      HALF_DAY  — partial attendance (future expansion)
        //      WFH       — work from home (future expansion)
        //      FIELD     — field assignment (future expansion)
        // ----------------------------------------------------------------
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('work_calendar_id')->nullable()->constrained('work_calendars')->nullOnDelete();
            $table->date('attendance_date');
            $table->string('status')->default('ABSENT');            // see values above
            $table->timestamp('clock_in_at')->nullable();
            $table->timestamp('clock_out_at')->nullable();
            $table->integer('late_minutes')->default(0);            // computed
            $table->integer('early_leave_minutes')->default(0);     // computed
            $table->integer('effective_work_minutes')->default(0);  // computed
            $table->string('clock_in_source')->default('MANUAL');   // MANUAL | ACCESS_LOG | KIOSK
            $table->string('clock_out_source')->default('MANUAL');
            $table->unsignedBigInteger('access_log_in_id')->nullable();   // reference (NOT FK — AccessLog is immutable)
            $table->unsignedBigInteger('access_log_out_id')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('verified_by')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['employee_id', 'attendance_date'], 'uq_attendance_emp_date');
            $table->index('attendance_date', 'idx_att_date');
            $table->index('status', 'idx_att_status');
        });

        // ----------------------------------------------------------------
        // Post-check: unchanged tables must not have lost rows
        // ----------------------------------------------------------------
        foreach ($beforeCounts as $tbl => $before) {
            $after = DB::table($tbl)->count();
            if ($after !== $before) {
                throw new \RuntimeException(
                    "Sprint 8 migration row-count invariant violated: {$tbl} had {$before} rows before, has {$after} after."
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('employee_calendar_assignments');
        Schema::dropIfExists('public_holidays');
        Schema::dropIfExists('work_schedule_days');
        Schema::dropIfExists('work_calendars');
    }
};
