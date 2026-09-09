<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobOffer;
use App\Models\JobVacancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecruitmentService
{
    /**
     * Generate unique Vacancy code e.g. VAC-2026-001
     */
    public function generateVacancyCode(): string
    {
        $year = date('Y');
        $count = JobVacancy::whereYear('created_at', $year)->count() + 1;
        return sprintf('VAC-%s-%03d', $year, $count);
    }

    /**
     * Generate unique Candidate code e.g. CND-2026-0001
     */
    public function generateCandidateNo(): string
    {
        $year = date('Y');
        $count = Candidate::whereYear('created_at', $year)->count() + 1;
        return sprintf('CND-%s-%04d', $year, $count);
    }

    /**
     * Generate unique Application code e.g. APP-2026-0001
     */
    public function generateApplicationNo(): string
    {
        $year = date('Y');
        $count = JobApplication::whereYear('created_at', $year)->count() + 1;
        return sprintf('APP-%s-%04d', $year, $count);
    }

    /**
     * Create a new Job Vacancy
     */
    public function createVacancy(array $data, ?Admin $creator = null): JobVacancy
    {
        if (empty($data['vacancy_code'])) {
            $data['vacancy_code'] = $this->generateVacancyCode();
        }
        if ($creator) {
            $data['created_by'] = $creator->id;
        }

        $vacancy = JobVacancy::create($data);

        ActivityLog::create([
            'admin_id' => $creator?->id,
            'action' => 'vacancy_created',
            'description' => "Lowongan {$vacancy->title} ({$vacancy->vacancy_code}) dibuat.",
            'timestamp' => now(),
        ]);

        return $vacancy;
    }

    /**
     * Register or find Candidate
     */
    public function createCandidate(array $data): Candidate
    {
        if (empty($data['candidate_no'])) {
            $data['candidate_no'] = $this->generateCandidateNo();
        }

        return Candidate::create($data);
    }

    /**
     * Submit an Application for a Vacancy
     */
    public function submitApplication(JobVacancy $vacancy, Candidate $candidate, array $extra = []): JobApplication
    {
        $applicationNo = $this->generateApplicationNo();

        $application = JobApplication::create([
            'application_no' => $applicationNo,
            'job_vacancy_id' => $vacancy->id,
            'candidate_id' => $candidate->id,
            'current_stage' => 'APPLIED',
            'status' => 'ACTIVE',
            'applied_at' => now(),
            'expected_salary' => $extra['expected_salary'] ?? null,
            'notes' => $extra['notes'] ?? null,
        ]);

        return $application;
    }

    /**
     * Transition application to a new stage with audit
     */
    public function transitionStage(JobApplication $application, string $newStage, ?string $reason = null, ?Admin $actor = null): JobApplication
    {
        $oldStage = $application->current_stage;
        $application->current_stage = $newStage;

        if ($newStage === 'ACCEPTED') {
            $application->status = 'HIRED';
        } elseif ($newStage === 'REJECTED') {
            $application->status = 'REJECTED';
            $application->rejection_reason = $reason;
        } elseif ($newStage === 'WITHDRAWN') {
            $application->status = 'WITHDRAWN';
        }

        $application->save();

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'application_stage_changed',
            'description' => "Lamaran {$application->application_no} dipindahkan dari {$oldStage} ke {$newStage}.",
            'timestamp' => now(),
        ]);

        return $application;
    }

    /**
     * Schedule an Interview
     */
    public function scheduleInterview(JobApplication $application, array $data, ?Admin $actor = null): Interview
    {
        $interview = Interview::create([
            'job_application_id' => $application->id,
            'stage_code' => $data['stage_code'] ?? $application->current_stage,
            'interviewer_id' => $data['interviewer_id'] ?? $actor?->id,
            'scheduled_at' => $data['scheduled_at'],
            'duration_minutes' => $data['duration_minutes'] ?? 45,
            'location_or_link' => $data['location_or_link'] ?? 'Kantor PKP',
            'status' => 'SCHEDULED',
        ]);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'interview_scheduled',
            'description' => "Interview untuk lamaran {$application->application_no} dijadwalkan pada {$interview->scheduled_at}.",
            'timestamp' => now(),
        ]);

        return $interview;
    }

    /**
     * Submit Interview Feedback
     */
    public function submitInterviewFeedback(Interview $interview, array $data, ?Admin $interviewer = null): Interview
    {
        $interview->feedback = $data['feedback'] ?? $interview->feedback;
        $interview->score = $data['score'] ?? $interview->score;
        $interview->recommendation = $data['recommendation'] ?? $interview->recommendation;
        $interview->status = 'COMPLETED';
        $interview->completed_at = now();
        $interview->save();

        ActivityLog::create([
            'admin_id' => $interviewer?->id,
            'action' => 'interview_feedback_submitted',
            'description' => "Feedback interview {$interview->id} untuk lamaran {$interview->application->application_no} disimpan. Rekomendasi: {$interview->recommendation}.",
            'timestamp' => now(),
        ]);

        return $interview;
    }

    /**
     * Create Job Offer
     */
    public function createOffer(JobApplication $application, array $data, ?Admin $creator = null): JobOffer
    {
        $offer = JobOffer::create([
            'job_application_id' => $application->id,
            'offered_salary' => $data['offered_salary'],
            'start_date' => $data['start_date'],
            'expiry_date' => $data['expiry_date'],
            'status' => $data['status'] ?? 'SENT',
            'terms' => $data['terms'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $creator?->id,
        ]);

        $this->transitionStage($application, 'OFFER', null, $creator);

        return $offer;
    }

    /**
     * Seamless Candidate to Employee Master Conversion
     */
    public function hireAndConvertToEmployee(JobApplication $application, array $overrideData = [], ?Admin $actor = null): Employee
    {
        return DB::transaction(function () use ($application, $overrideData, $actor) {
            $candidate = $application->candidate;
            $vacancy = $application->vacancy;

            // Mark application as hired
            $this->transitionStage($application, 'ACCEPTED', null, $actor);

            // Generate employee number if not provided
            $employeeNo = $overrideData['employee_no'] ?? null;
            if (empty($employeeNo)) {
                $year = date('Y');
                $count = Employee::count() + 1;
                $employeeNo = sprintf('EMP-%s-%04d', $year, $count);
            }

            // Create employee master record
            $employee = Employee::create([
                'employee_id' => $employeeNo,
                'nik' => $candidate->national_id ?? 'NIK-' . date('Ymd') . '-' . rand(100, 999),
                'name' => $candidate->full_name,
                'email' => $candidate->email,
                'phone' => $candidate->phone,
                'division_id' => $overrideData['division_id'] ?? $vacancy?->division_id,
                'position_id' => $overrideData['position_id'] ?? $vacancy?->position_id,
                'building_id' => $overrideData['building_id'] ?? $vacancy?->building_id,
                'employment_status' => $overrideData['employment_status'] ?? strtolower($vacancy?->employment_type ?? 'permanent'),
                'hire_date' => now()->toDateString(),
                'role' => $vacancy?->title ?? 'Staff',
                'role_jabatan' => $vacancy?->title ?? 'Staff',
                'department' => $vacancy?->division?->name ?? 'Umum',
            ]);

            // Link candidate to newly created employee
            $candidate->converted_employee_id = $employee->id;
            $candidate->save();

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'candidate_converted_to_employee',
                'description' => "Kandidat {$candidate->full_name} ({$candidate->candidate_no}) resmi dikonversi menjadi Karyawan ({$employee->employee_id}).",
                'timestamp' => now(),
            ]);

            return $employee;
        });
    }

    /**
     * Get Recruitment metrics summary
     */
    public function getMetrics(): array
    {
        $totalVacancies = JobVacancy::where('status', 'OPEN')->count();
        $totalCandidates = Candidate::count();
        $activeApplications = JobApplication::where('status', 'ACTIVE')->count();
        $scheduledInterviews = Interview::where('status', 'SCHEDULED')->count();
        $hiredCount = JobApplication::where('status', 'HIRED')->count();
        $totalApplications = JobApplication::count();

        $hireRate = $totalApplications > 0 ? round(($hiredCount / $totalApplications) * 100, 1) : 0.0;

        return [
            'open_vacancies' => $totalVacancies,
            'total_candidates' => $totalCandidates,
            'active_applications' => $activeApplications,
            'scheduled_interviews' => $scheduledInterviews,
            'hired_count' => $hiredCount,
            'hire_rate_percentage' => $hireRate,
        ];
    }
}
