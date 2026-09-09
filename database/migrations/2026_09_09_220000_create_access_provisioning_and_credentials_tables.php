<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Access Profiles
        Schema::create('access_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->string('building_name', 100)->nullable();
            $table->json('allowed_doors')->nullable();
            $table->string('schedule_type', 32)->default('BUSINESS_HOURS'); // ALL_DAY, BUSINESS_HOURS, CUSTOM_WINDOW
            $table->string('start_time', 10)->default('08:00');
            $table->string('end_time', 10)->default('18:00');
            $table->boolean('is_active')->default(true);
            $table->json('employment_type_restrictions')->nullable();
            $table->timestamps();

            $table->index('building_name');
            $table->index('is_active');
        });

        // 2. Access Requests
        Schema::create('access_requests', function (Blueprint $table) {
            $table->id();
            $table->string('request_number', 32)->unique();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('internship_id')->nullable()->constrained('internships')->nullOnDelete();
            $table->foreignId('access_profile_id')->nullable()->constrained('access_profiles')->nullOnDelete();
            $table->json('specific_doors')->nullable();
            $table->string('building_name', 100)->nullable();
            $table->text('business_reason');
            $table->string('status', 32)->default('PENDING_APPROVAL'); // DRAFT, SUBMITTED, PENDING_APPROVAL, APPROVED, REJECTED, PROVISIONING, ACTIVE, SUSPENDED, REVOKED, EXPIRED, FAILED
            $table->foreignId('requested_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->date('valid_from');
            $table->date('valid_until')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'valid_from']);
            $table->index('building_name');
        });

        // 3. Credential Records
        Schema::create('credential_records', function (Blueprint $table) {
            $table->id();
            $table->string('credential_number', 32)->unique();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('internship_id')->nullable()->constrained('internships')->nullOnDelete();
            $table->string('credential_type', 32)->default('CARD'); // CARD, FINGERPRINT_STATUS, FACE_STATUS, PIN_STATUS, QR, MOBILE
            $table->string('card_number', 64)->nullable();
            $table->string('masked_identifier', 64);
            $table->string('external_reference', 100)->nullable();
            $table->string('biometric_status', 32)->default('NOT_ENROLLED'); // NOT_ENROLLED, PENDING, ENROLLED, SYNC_PENDING, SYNCED, FAILED, REVOKED
            $table->string('status', 32)->default('ACTIVE'); // ACTIVE, PENDING, SUSPENDED, REVOKED, EXPIRED
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['credential_type', 'status']);
        });

        // 4. Device Sync Queue
        Schema::create('credential_device_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('door_id')->constrained('doors')->cascadeOnDelete();
            $table->foreignId('credential_record_id')->constrained('credential_records')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('operation', 32)->default('ADD'); // ADD, UPDATE, REVOKE, DELETE
            $table->string('status', 32)->default('QUEUED'); // QUEUED, PROCESSING, SUCCESS, FAILED, RETRY_PENDING, CANCELLED
            $table->unsignedInteger('attempt_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->text('error_summary')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->timestamps();

            $table->index(['door_id', 'status']);
            $table->index(['status', 'attempt_count']);
        });

        // 5. E-Money Registry (Strictly Admin-Only)
        Schema::create('emoney_cards', function (Blueprint $table) {
            $table->id();
            $table->string('card_uuid', 32)->unique();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('internship_id')->nullable()->constrained('internships')->nullOnDelete();
            $table->string('provider', 32)->default('MANDIRI_EMONEY'); // MANDIRI_EMONEY, BCA_FLAZZ, BNI_TAPCASH, BRI_BRIZZI, JAKCARD, OTHER
            $table->string('masked_card_number', 32);
            $table->string('card_hash', 64)->unique();
            $table->string('status', 32)->default('AVAILABLE'); // AVAILABLE, ASSIGNED, ACTIVE, SUSPENDED, LOST, RETURNED, EXPIRED, REVOKED
            $table->date('issued_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->foreignId('assigned_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->dateTime('assigned_at')->nullable();
            $table->dateTime('returned_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['provider', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emoney_cards');
        Schema::dropIfExists('credential_device_syncs');
        Schema::dropIfExists('credential_records');
        Schema::dropIfExists('access_requests');
        Schema::dropIfExists('access_profiles');
    }
};
