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
        // 1. Onboarding Cases
        Schema::create('onboarding_cases', function (Blueprint $table) {
            $table->id();
            $table->string('case_number', 32)->unique();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('internship_id')->nullable()->constrained('internships')->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->nullOnDelete();
            $table->string('status', 32)->default('PENDING'); // PENDING, IN_PROGRESS, BLOCKED, COMPLETED, CANCELLED
            $table->date('start_date');
            $table->date('target_completion_date')->nullable();
            $table->foreignId('hr_owner_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained('positions')->nullOnDelete();
            $table->string('employment_type', 32)->default('PERMANENT'); // PERMANENT, FIXED_TERM, PROBATION, INTERNSHIP
            $table->string('work_location', 100)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'start_date']);
            $table->index(['employee_id', 'status']);
        });

        // 2. Onboarding Tasks (Checklist)
        Schema::create('onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_case_id')->constrained('onboarding_cases')->cascadeOnDelete();
            $table->string('task_key', 64);
            $table->string('title', 255);
            $table->string('category', 32)->default('HR_ADMIN'); // HR_ADMIN, IT_ACCESS, FACILITY, ORGANIZATION, ORIENTATION, COMPLIANCE
            $table->string('status', 32)->default('PENDING'); // PENDING, IN_PROGRESS, COMPLETED, BLOCKED, NOT_REQUIRED
            $table->boolean('is_required')->default(true);
            $table->integer('order_index')->default(0);
            $table->date('due_date')->nullable();
            $table->string('assigned_to', 100)->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->text('blocker_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['onboarding_case_id', 'status']);
            $table->index(['onboarding_case_id', 'is_required']);
        });

        // 3. Contracts
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number', 64)->unique();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('internship_id')->nullable()->constrained('internships')->nullOnDelete();
            $table->string('contract_type', 32)->default('FIXED_TERM'); // PERMANENT, FIXED_TERM, PROBATION, INTERNSHIP, FREELANCE, NDA, OTHER
            $table->string('title', 255);
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('effective_date')->nullable();
            $table->string('status', 32)->default('DRAFT'); // DRAFT, PENDING_SIGNATURE, ACTIVE, EXPIRED, TERMINATED, RENEWED, CANCELLED
            $table->date('signed_date')->nullable();
            $table->string('signed_by_employee', 100)->nullable();
            $table->string('signed_by_company', 100)->nullable();
            $table->string('renewal_status', 32)->default('NONE'); // NONE, ELIGIBLE, RENEWED, NOT_RENEWED
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status']);
            $table->index(['status', 'end_date']);
        });

        // 4. Employee Documents (Secure HR Documents)
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->string('document_number', 64)->unique();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('internship_id')->nullable()->constrained('internships')->nullOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('contracts')->nullOnDelete();
            $table->string('category', 32)->default('OTHER'); // IDENTITY, CONTRACT, NDA, EDUCATION, CERTIFICATION, ASSIGNMENT, TRAINING, MEDICAL, PERFORMANCE, INTERNSHIP, OTHER
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('file_path', 500); // Path in private disk (storage/app/...)
            $table->string('file_name', 255); // Original sanitized filename for download
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('checksum', 64)->nullable(); // SHA-256
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('parent_document_id')->nullable()->constrained('employee_documents')->nullOnDelete();
            $table->string('status', 32)->default('PENDING_VERIFICATION'); // PENDING_VERIFICATION, VERIFIED, REJECTED, ARCHIVED
            $table->string('visibility', 32)->default('CONFIDENTIAL_HR'); // CONFIDENTIAL_HR, SUPERVISOR_SHARED, EMPLOYEE_VISIBLE, PUBLIC_INTERNAL
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('issuer', 255)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->text('verification_notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'category']);
            $table->index(['internship_id', 'category']);
            $table->index(['status', 'visibility']);
            $table->index('expiry_date');
        });

        // 5. Document Acknowledgements
        Schema::create('document_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('employee_documents')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('internship_id')->nullable()->constrained('internships')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->dateTime('acknowledged_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_acknowledgements');
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('contracts');
        Schema::dropIfExists('onboarding_tasks');
        Schema::dropIfExists('onboarding_cases');
    }
};
