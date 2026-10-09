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
        // 1. Internships Master Table
        Schema::create('internships', function (Blueprint $table) {
            $table->id();
            $table->string('intern_id')->unique(); // e.g. INT-2026-0001
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('candidate_id')->nullable()->constrained('candidates')->nullOnDelete();
            $table->foreignId('job_application_id')->nullable()->constrained('job_applications')->nullOnDelete();
            $table->string('institution'); // Campus / Vocational School
            $table->string('major'); // Program Studi / Jurusan
            $table->string('education_level')->default('S1'); // SMK, D3, S1, S2
            $table->integer('semester')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->foreignId('division_id')->nullable()->constrained('divisions')->nullOnDelete();
            $table->string('position_title')->default('Intern');
            $table->foreignId('mentor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('supervisor_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('campus_supervisor_name')->nullable();
            $table->string('campus_supervisor_contact')->nullable();
            $table->text('project_assignment')->nullable();
            $table->string('status')->default('ACTIVE'); // PENDING, ACCEPTED, ACTIVE, SUSPENDED, COMPLETED, TERMINATED, CANCELLED
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_notes')->nullable();
            $table->string('certificate_no')->nullable();
            $table->boolean('access_revocation_marked')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'division_id']);
            $table->index('mentor_id');
        });

        // 2. Daily Activity / Logbook
        Schema::create('internship_daily_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained('internships')->cascadeOnDelete();
            $table->date('activity_date');
            $table->string('title');
            $table->text('description');
            $table->string('project_task_ref')->nullable();
            $table->string('start_time', 10)->nullable();
            $table->string('end_time', 10)->nullable();
            $table->integer('progress_percent')->default(100);
            $table->string('attachment_url')->nullable();
            $table->string('status')->default('SUBMITTED'); // DRAFT, SUBMITTED, REVIEWED, REJECTED
            $table->text('mentor_notes')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['internship_id', 'activity_date']);
            $table->index('status');
        });

        // 3. Monthly & Periodic Reports
        Schema::create('internship_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained('internships')->cascadeOnDelete();
            $table->string('report_type')->default('MONTHLY'); // MONTHLY, MID_TERM, FINAL
            $table->string('period_month', 20)->nullable(); // e.g. "2026-09"
            $table->string('title');
            $table->text('summary');
            $table->text('achievements')->nullable();
            $table->text('issues_and_blockers')->nullable();
            $table->text('intern_notes')->nullable();
            $table->text('mentor_notes')->nullable();
            $table->string('attachment_url')->nullable();
            $table->string('status')->default('SUBMITTED'); // DRAFT, SUBMITTED, REVIEWED, APPROVED, REVISION_REQUIRED
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['internship_id', 'period_month']);
            $table->index('status');
        });

        // 4. Evaluations & Scorecards
        Schema::create('internship_evaluations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('internship_id')->constrained('internships')->cascadeOnDelete();
            $table->unsignedBigInteger('evaluator_id')->nullable();
            $table->string('evaluator_name')->nullable();
            $table->string('evaluator_role')->nullable(); // Mentor, HRD, Supervisor
            $table->string('evaluation_type')->default('FINAL'); // MID_TERM, FINAL
            $table->integer('discipline_score')->default(80);
            $table->integer('communication_score')->default(80);
            $table->integer('technical_score')->default(80);
            $table->integer('initiative_score')->default(80);
            $table->integer('teamwork_score')->default(80);
            $table->integer('attendance_score')->default(80);
            $table->integer('task_completion_score')->default(80);
            $table->integer('professionalism_score')->default(80);
            $table->decimal('average_score', 5, 2);
            $table->text('strengths')->nullable();
            $table->text('improvements')->nullable();
            $table->string('final_recommendation')->default('COMPLETE'); // HIRE_AS_EMPLOYEE, EXTEND_INTERNSHIP, COMPLETE, NOT_RECOMMENDED
            $table->date('evaluated_at');
            $table->timestamps();

            $table->index(['internship_id', 'evaluation_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('internship_evaluations');
        Schema::dropIfExists('internship_reports');
        Schema::dropIfExists('internship_daily_activities');
        Schema::dropIfExists('internships');
    }
};
