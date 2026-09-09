<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Recruitment Stages Master
        Schema::create('recruitment_stages', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // APPLIED, SCREENING, HR_INTERVIEW, TECHNICAL_TEST, USER_INTERVIEW, MANAGEMENT_REVIEW, OFFER
            $table->string('name');
            $table->integer('sequence')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // 2. Job Vacancies
        Schema::create('job_vacancies', function (Blueprint $table) {
            $table->id();
            $table->string('vacancy_code')->unique(); // VAC-2026-001
            $table->string('title');
            $table->foreignId('division_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('building_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employment_type')->default('FULL_TIME'); // FULL_TIME, CONTRACT, INTERNSHIP, PART_TIME
            $table->string('experience_level')->default('MID'); // ENTRY, JUNIOR, MID, SENIOR, LEAD
            $table->integer('quota')->default(1);
            $table->unsignedBigInteger('salary_min')->nullable();
            $table->unsignedBigInteger('salary_max')->nullable();
            $table->text('description');
            $table->text('requirements')->nullable();
            $table->string('status')->default('OPEN'); // DRAFT, OPEN, CLOSED, ARCHIVED
            $table->date('deadline')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        // 3. Candidates (Talent Pool)
        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->string('candidate_no')->unique(); // CND-2026-0001
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email')->unique();
            $table->string('phone');
            $table->string('national_id')->nullable();
            $table->text('address')->nullable();
            $table->string('current_company')->nullable();
            $table->string('current_position')->nullable();
            $table->string('resume_path')->nullable();
            $table->string('portfolio_url')->nullable();
            $table->string('linkedin_url')->nullable();
            $table->string('source')->default('CAREER_SITE'); // CAREER_SITE, LINKEDIN, REFERRAL, JOB_FAIR, INTERNAL
            $table->boolean('talent_pool_status')->default(false);
            $table->foreignId('converted_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 4. Job Applications
        Schema::create('job_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no')->unique(); // APP-2026-0001
            $table->foreignId('job_vacancy_id')->constrained('job_vacancies')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('candidates')->cascadeOnDelete();
            $table->string('current_stage')->default('APPLIED'); // APPLIED, SCREENING, HR_INTERVIEW, TECHNICAL_TEST, USER_INTERVIEW, MANAGEMENT_REVIEW, OFFER
            $table->string('status')->default('ACTIVE'); // ACTIVE, HIRED, REJECTED, WITHDRAWN
            $table->timestamp('applied_at')->useCurrent();
            $table->unsignedBigInteger('expected_salary')->nullable();
            $table->tinyInteger('rating')->nullable(); // 1 to 5
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 5. Interviews
        Schema::create('interviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained('job_applications')->cascadeOnDelete();
            $table->string('stage_code'); // HR_INTERVIEW, TECHNICAL_TEST, USER_INTERVIEW, MANAGEMENT_REVIEW
            $table->foreignId('interviewer_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->dateTime('scheduled_at');
            $table->integer('duration_minutes')->default(45);
            $table->string('location_or_link')->default('Kantor PKP');
            $table->string('status')->default('SCHEDULED'); // SCHEDULED, COMPLETED, CANCELLED, RESCHEDULED
            $table->text('feedback')->nullable();
            $table->integer('score')->nullable(); // 1-100
            $table->string('recommendation')->nullable(); // PROCEED, HOLD, REJECT
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();
        });

        // 6. Assessments
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained('job_applications')->cascadeOnDelete();
            $table->string('title');
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->default(100.00);
            $table->string('status')->default('PENDING'); // PENDING, SUBMITTED, EVALUATED
            $table->text('notes')->nullable();
            $table->foreignId('evaluated_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });

        // 7. Job Offers
        Schema::create('job_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_application_id')->constrained('job_applications')->cascadeOnDelete();
            $table->unsignedBigInteger('offered_salary');
            $table->date('start_date');
            $table->date('expiry_date');
            $table->string('status')->default('DRAFT'); // DRAFT, SENT, ACCEPTED, DECLINED, EXPIRED
            $table->text('terms')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_offers');
        Schema::dropIfExists('assessments');
        Schema::dropIfExists('interviews');
        Schema::dropIfExists('job_applications');
        Schema::dropIfExists('candidates');
        Schema::dropIfExists('job_vacancies');
        Schema::dropIfExists('recruitment_stages');
    }
};
