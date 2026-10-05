<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive only. Device reconciliation is read-only towards the Hikvision devices and
 * never rewrites employees: what a device reports is kept beside the app record so the
 * two can be compared, and a human decision about an unmatched device person is stored
 * as a link here instead of changing the employee's identity fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_reconciliation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 20)->default('read_only');
            $table->foreignId('triggered_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('status', 20)->default('running'); // running, completed, partial, failed
            $table->json('door_ids')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Outcome of one run for one device: reachable or not, and what was read.
        Schema::create('device_reconciliation_door_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('device_reconciliation_runs')->cascadeOnDelete();
            $table->foreignId('door_id')->constrained('doors')->cascadeOnDelete();
            $table->boolean('reachable')->default(false);
            $table->string('error', 255)->nullable();
            $table->unsignedInteger('total_users')->default(0);
            $table->unsignedInteger('total_cards')->default(0);
            $table->unsignedInteger('total_fingerprints')->default(0);
            $table->json('counts')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['door_id', 'id']);
        });

        // Latest observed state of one person on one device (upserted, so re-runs are idempotent).
        Schema::create('device_person_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('door_id')->constrained('doors')->cascadeOnDelete();
            $table->string('device_employee_no', 64);
            $table->string('device_name', 191)->nullable();
            $table->string('device_enabled', 20)->nullable();
            $table->unsignedInteger('card_count')->nullable();
            $table->json('card_hashes')->nullable();   // HMAC only, never plaintext card numbers
            $table->json('card_masks')->nullable();    // ****1234 for human comparison
            $table->unsignedInteger('fingerprint_count')->nullable();
            $table->unsignedInteger('face_count')->nullable();
            $table->boolean('present_on_device')->default(true);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('candidate_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('match_basis', 40)->nullable();
            $table->string('confidence', 10)->default('none');
            $table->string('status', 30);
            $table->string('card_status', 30)->nullable();
            $table->string('fingerprint_status', 30)->nullable();
            $table->json('reasons')->nullable();
            $table->foreignId('last_run_id')->nullable()->constrained('device_reconciliation_runs')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->unique(['door_id', 'device_employee_no']);
            $table->index(['employee_id']);
            $table->index(['status']);
        });

        Schema::create('device_person_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('door_id')->constrained('doors')->cascadeOnDelete();
            $table->string('device_employee_no', 64);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('decision', 20); // LINKED, REVIEW, IGNORED
            $table->foreignId('decided_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->timestamps();
            $table->unique(['door_id', 'device_employee_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_person_links');
        Schema::dropIfExists('device_person_states');
        Schema::dropIfExists('device_reconciliation_door_results');
        Schema::dropIfExists('device_reconciliation_runs');
    }
};
