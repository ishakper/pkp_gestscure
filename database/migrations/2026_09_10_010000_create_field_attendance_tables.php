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
     *  - Additive column attendance_type on attendances defaults to 'OFFICE'.
     *  - Geofence coordinates and radius stored on field_locations.
     *  - Strict foreign keys and cascading where appropriate.
     */
    public function up(): void
    {
        // 0. Row-count pre-check on existing tables
        $tablesToCheck = ['employees', 'access_logs', 'admins', 'attendances', 'doors', 'work_calendars', 'attendance_evidences'];
        $beforeCounts = [];
        foreach ($tablesToCheck as $tbl) {
            if (Schema::hasTable($tbl)) {
                $beforeCounts[$tbl] = DB::table($tbl)->count();
            }
        }

        // 1. Add attendance_type to attendances table if missing
        if (Schema::hasTable('attendances') && !Schema::hasColumn('attendances', 'attendance_type')) {
            Schema::table('attendances', function (Blueprint $table) {
                $table->string('attendance_type')->default('OFFICE')->after('status')->index();
            });
        }

        // 2. Create field_locations table
        Schema::create('field_locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('project_name')->nullable();
            $table->string('client_name')->nullable();
            $table->text('site_address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->integer('radius_meters')->default(100);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('created_by')->references('id')->on('admins')->nullOnDelete();
            $table->index('name');
        });

        // 3. Create field_assignments table
        Schema::create('field_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('field_location_id')->constrained('field_locations')->cascadeOnDelete();
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedBigInteger('supervisor_id')->nullable();
            $table->string('status')->default('ACTIVE')->index(); // ACTIVE, PENDING, COMPLETED, REVOKED
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->datetime('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('supervisor_id')->references('id')->on('employees')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('admins')->nullOnDelete();
            $table->index(['employee_id', 'status']);
            $table->index(['start_date', 'end_date']);
        });

        // 4. Create field_attendance_evidences table
        Schema::create('field_attendance_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('field_assignment_id')->constrained('field_assignments')->cascadeOnDelete();
            $table->foreignId('field_location_id')->constrained('field_locations')->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->nullOnDelete();
            $table->date('attendance_date')->index();
            $table->string('type')->index(); // CHECK_IN, CHECK_OUT
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('accuracy_meters', 8, 2);
            $table->datetime('captured_at');
            $table->datetime('server_received_at');
            $table->decimal('distance_meters', 10, 2);
            $table->string('geofence_result')->default('VALID')->index(); // VALID, OUTSIDE_GEOFENCE, LOW_ACCURACY, UNAVAILABLE, ANOMALY
            $table->string('photo_path');
            $table->string('photo_mime');
            $table->integer('photo_size_bytes');
            $table->json('anomaly_flags')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_override')->default(false);
            $table->text('override_reason')->nullable();
            $table->unsignedBigInteger('override_by')->nullable();
            $table->datetime('override_at')->nullable();
            $table->timestamps();

            $table->foreign('override_by')->references('id')->on('admins')->nullOnDelete();
            $table->index(['employee_id', 'attendance_date', 'type']);
        });

        // 5. Post-check: ensure unchanged tables have lost 0 rows
        foreach ($beforeCounts as $tbl => $before) {
            $after = DB::table($tbl)->count();
            if ($after !== $before) {
                throw new \RuntimeException(
                    "Sprint 10 migration row preservation invariant violated: {$tbl} had {$before} rows before, has {$after} after."
                );
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('field_attendance_evidences');
        Schema::dropIfExists('field_assignments');
        Schema::dropIfExists('field_locations');

        if (Schema::hasTable('attendances') && Schema::hasColumn('attendances', 'attendance_type')) {
            if (DB::getDriverName() !== 'sqlite') {
                Schema::table('attendances', function (Blueprint $table) {
                    $table->dropIndex(['attendance_type']);
                });
            }

            Schema::table('attendances', function (Blueprint $table) {
                $table->dropColumn('attendance_type');
            });
        }
    }
};
