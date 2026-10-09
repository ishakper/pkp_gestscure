<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::disableForeignKeyConstraints();

        if (Schema::hasTable('access_logs')) {
            // Drop temp table if exists from previous aborted migration
            Schema::dropIfExists('access_logs_old_temp');

            // Rename current table to backup table
            Schema::rename('access_logs', 'access_logs_old_temp');

            // Drop any lingering indexes from old table to prevent conflict
            $oldIndexes = [
                'access_logs_door_id_index',
                'access_logs_employee_id_index',
                'access_logs_nik_index',
                'access_logs_scanned_at_index',
                'access_logs_timestamp_index',
                'access_logs_log_id_unique',
                'access_logs_event_type_index',
            ];
            foreach ($oldIndexes as $idx) {
                try {
                    DB::statement("DROP INDEX IF EXISTS {$idx}");
                } catch (\Throwable $e) {
                    // Ignore if index doesn't exist
                }
            }

            // Create new table with pure VARCHAR/TEXT string types without SQLite CHECK constraints
            Schema::create('access_logs', function (Blueprint $table) {
                $table->id();
                $table->string('log_id')->unique();
                $table->foreignId('door_id')->constrained('doors')->onDelete('cascade');
                $table->foreignId('employee_id')->nullable()->constrained('employees')->onDelete('set null');
                $table->string('nik')->nullable();
                $table->string('event_type')->nullable()->default('STANDARD_TAP');
                $table->string('device_ip')->nullable();
                $table->string('auth_method')->nullable();
                $table->string('verify_method')->nullable();
                $table->string('status')->default('Granted');
                $table->string('access_status')->default('Granted');
                $table->string('reason')->nullable();
                $table->timestamp('scanned_at')->nullable();
                $table->timestamp('timestamp')->nullable();
                $table->timestamps();

                $table->index('door_id');
                $table->index('employee_id');
                $table->index('nik');
                $table->index('event_type');
                $table->index('scanned_at');
                $table->index('timestamp');
            });

            // Copy existing data across
            $columns = Schema::getColumnListing('access_logs_old_temp');
            $hasEventType = in_array('event_type', $columns);

            if ($hasEventType) {
                DB::statement('INSERT INTO access_logs (id, log_id, door_id, employee_id, nik, event_type, device_ip, auth_method, verify_method, status, access_status, reason, scanned_at, timestamp, created_at, updated_at) 
                    SELECT id, log_id, door_id, employee_id, nik, COALESCE(event_type, "STANDARD_TAP"), device_ip, auth_method, verify_method, status, access_status, reason, scanned_at, timestamp, created_at, updated_at 
                    FROM access_logs_old_temp');
            } else {
                DB::statement('INSERT INTO access_logs (id, log_id, door_id, employee_id, nik, event_type, device_ip, auth_method, verify_method, status, access_status, reason, scanned_at, timestamp, created_at, updated_at) 
                    SELECT id, log_id, door_id, employee_id, nik, "STANDARD_TAP", device_ip, auth_method, verify_method, status, access_status, reason, scanned_at, timestamp, created_at, updated_at 
                    FROM access_logs_old_temp');
            }

            Schema::dropIfExists('access_logs_old_temp');
        }

        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('access_logs');
        Schema::enableForeignKeyConstraints();
    }
};
