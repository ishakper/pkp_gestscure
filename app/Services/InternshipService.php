<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Internship;
use App\Models\InternshipDailyActivity;
use App\Models\InternshipEvaluation;
use App\Models\InternshipReport;
use App\Models\JobApplication;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InternshipService
{
    /**
     * Generate unique Intern ID (INT-YYYY-XXXX)
     */
    public function generateInternId(): string
    {
        $year = date('Y');
        $count = Internship::withTrashed()->count() + 1;
        return sprintf('INT-%s-%04d', $year, $count);
    }

    /**
     * Create a new Internship record directly
     */
    public function createInternship(array $data, ?Admin $actor = null): Internship
    {
        if (!empty($data['mentor_id']) && !empty($data['employee_id']) && (int) $data['mentor_id'] === (int) $data['employee_id']) {
            throw new InvalidArgumentException('Mentor tidak boleh merupakan karyawan/pemagang yang sama.');
        }

        if (!empty($data['mentor_id'])) {
            $mentor = Employee::find($data['mentor_id']);
            if (!$mentor) {
                throw new InvalidArgumentException('Mentor harus merupakan data karyawan aktif yang valid.');
            }
        }

        $internId = $data['intern_id'] ?? $this->generateInternId();

        $internship = Internship::create([
            'intern_id' => $internId,
            'employee_id' => $data['employee_id'] ?? null,
            'candidate_id' => $data['candidate_id'] ?? null,
            'job_application_id' => $data['job_application_id'] ?? null,
            'institution' => $data['institution'],
            'major' => $data['major'],
            'education_level' => $data['education_level'] ?? 'S1',
            'semester' => $data['semester'] ?? null,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'division_id' => $data['division_id'] ?? null,
            'position_title' => $data['position_title'] ?? 'Intern',
            'mentor_id' => $data['mentor_id'] ?? null,
            'supervisor_id' => $data['supervisor_id'] ?? null,
            'campus_supervisor_name' => $data['campus_supervisor_name'] ?? null,
            'campus_supervisor_contact' => $data['campus_supervisor_contact'] ?? null,
            'project_assignment' => $data['project_assignment'] ?? null,
            'status' => $data['status'] ?? 'ACTIVE',
        ]);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'internship_created',
            'description' => "Program magang {$internship->intern_id} untuk {$internship->institution} ({$internship->major}) berhasil dibuat.",
            'timestamp' => now(),
        ]);

        return $internship;
    }

    /**
     * Convert an accepted candidate into an official intern & employee master record
     */
    public function convertCandidateToIntern(Candidate $candidate, array $internshipData, ?Admin $actor = null): Internship
    {
        return DB::transaction(function () use ($candidate, $internshipData, $actor) {
            // 1. Create employee master record if not exists
            $employee = null;
            if ($candidate->converted_employee_id) {
                $employee = Employee::find($candidate->converted_employee_id);
            }

            if (!$employee) {
                $year = date('Y');
                $count = Employee::count() + 1;
                $employeeNo = sprintf('INT-EMP-%s-%04d', $year, $count);

                $employee = Employee::create([
                    'employee_id' => $employeeNo,
                    'nik' => $candidate->national_id ?? 'NIK-INT-' . date('Ymd') . '-' . rand(100, 999),
                    'name' => $candidate->full_name,
                    'email' => $candidate->email,
                    'phone' => $candidate->phone,
                    'division_id' => $internshipData['division_id'] ?? null,
                    'building_id' => $internshipData['building_id'] ?? null,
                    'employment_type' => 'INTERNSHIP',
                    'employment_status' => 'active',
                    'hire_date' => $internshipData['start_date'] ?? now()->toDateString(),
                    'role' => $internshipData['position_title'] ?? 'Intern',
                    'role_jabatan' => $internshipData['position_title'] ?? 'Intern',
                    'department' => 'Magang / Internship',
                    'supervisor_id' => $internshipData['mentor_id'] ?? null,
                ]);

                $candidate->converted_employee_id = $employee->id;
                $candidate->save();
            }

            // 2. Validate mentor
            if (!empty($internshipData['mentor_id'])) {
                if ((int) $internshipData['mentor_id'] === (int) $employee->id) {
                    throw new InvalidArgumentException('Mentor tidak boleh merupakan pemagang yang sama.');
                }
                $mentor = Employee::find($internshipData['mentor_id']);
                if (!$mentor) {
                    throw new InvalidArgumentException('Mentor karyawan tidak valid.');
                }
            }

            // 3. Create Internship record
            $internId = $this->generateInternId();
            $internship = Internship::create([
                'intern_id' => $internId,
                'employee_id' => $employee->id,
                'candidate_id' => $candidate->id,
                'job_application_id' => $internshipData['job_application_id'] ?? null,
                'institution' => $internshipData['institution'] ?? 'Institusi Pendidikan',
                'major' => $internshipData['major'] ?? 'Bidang Studi',
                'education_level' => $internshipData['education_level'] ?? 'S1',
                'semester' => $internshipData['semester'] ?? null,
                'start_date' => $internshipData['start_date'],
                'end_date' => $internshipData['end_date'],
                'division_id' => $internshipData['division_id'] ?? null,
                'position_title' => $internshipData['position_title'] ?? 'Intern',
                'mentor_id' => $internshipData['mentor_id'] ?? null,
                'supervisor_id' => $internshipData['mentor_id'] ?? null,
                'campus_supervisor_name' => $internshipData['campus_supervisor_name'] ?? null,
                'campus_supervisor_contact' => $internshipData['campus_supervisor_contact'] ?? null,
                'project_assignment' => $internshipData['project_assignment'] ?? null,
                'status' => 'ACTIVE',
            ]);

            // 4. Update JobApplication status if linked
            if (!empty($internshipData['job_application_id'])) {
                $app = JobApplication::find($internshipData['job_application_id']);
                if ($app) {
                    $app->status = 'HIRED';
                    $app->current_stage_code = 'ACCEPTED';
                    $app->save();
                }
            }

            ActivityLog::create([
                'admin_id' => $actor?->id,
                'action' => 'candidate_converted_to_intern',
                'description' => "Kandidat {$candidate->full_name} ({$candidate->candidate_no}) resmi dikonversi ke Program Magang ({$internship->intern_id}) dan Pegawai ({$employee->employee_id}).",
                'timestamp' => now(),
            ]);

            return $internship;
        });
    }

    /**
     * Assign / Reassign Mentor
     */
    public function assignMentor(Internship $internship, int $mentorId, ?Admin $actor = null): Internship
    {
        if ($internship->employee_id && (int) $mentorId === (int) $internship->employee_id) {
            throw new InvalidArgumentException('Mentor tidak boleh merupakan pemagang yang sama.');
        }

        $mentor = Employee::find($mentorId);
        if (!$mentor) {
            throw new InvalidArgumentException('Mentor karyawan tidak ditemukan atau tidak aktif.');
        }

        $internship->mentor_id = $mentorId;
        $internship->save();

        if ($internship->employee) {
            $internship->employee->supervisor_id = $mentorId;
            $internship->employee->save();
        }

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'internship_mentor_assigned',
            'description' => "Mentor {$mentor->name} ditugaskan untuk pemagang {$internship->intern_id}.",
            'timestamp' => now(),
        ]);

        return $internship;
    }

    /**
     * Activate Internship
     */
    public function activateInternship(Internship $internship, ?Admin $actor = null): Internship
    {
        $internship->status = 'ACTIVE';
        $internship->save();

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'internship_activated',
            'description' => "Program magang {$internship->intern_id} diaktifkan.",
            'timestamp' => now(),
        ]);

        return $internship;
    }

    /**
     * Complete Internship with evaluation and certificate reference
     */
    public function completeInternship(Internship $internship, array $data, ?Admin $actor = null): Internship
    {
        $internship->status = 'COMPLETED';
        $internship->completed_at = now();
        $internship->completion_notes = $data['completion_notes'] ?? $internship->completion_notes;
        $internship->certificate_no = $data['certificate_no'] ?? sprintf('CERT-INT-%s-%04d', date('Y'), $internship->id);
        $internship->access_revocation_marked = true; // Flag for later Sprint 22 access handoff
        $internship->save();

        // Revoke active credentials and access requests upon internship completion
        app(\App\Services\AccessProvisioningService::class)->revokeInternAccess($internship, 'Program magang selesai (COMPLETED)', $actor);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'internship_completed',
            'description' => "Program magang {$internship->intern_id} telah selesai (Status: COMPLETED, Sertifikat: {$internship->certificate_no}).",
            'timestamp' => now(),
        ]);

        return $internship;
    }

    /**
     * Log Daily Activity
     */
    public function logDailyActivity(Internship $internship, array $data, ?Admin $actor = null): InternshipDailyActivity
    {
        $activity = InternshipDailyActivity::create([
            'internship_id' => $internship->id,
            'activity_date' => $data['activity_date'] ?? now()->toDateString(),
            'title' => $data['title'],
            'description' => $data['description'],
            'project_task_ref' => $data['project_task_ref'] ?? null,
            'start_time' => $data['start_time'] ?? '08:30',
            'end_time' => $data['end_time'] ?? '17:00',
            'progress_percent' => $data['progress_percent'] ?? 100,
            'attachment_url' => $data['attachment_url'] ?? null,
            'status' => $data['status'] ?? 'SUBMITTED',
        ]);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'internship_activity_logged',
            'description' => "Aktivitas harian '{$activity->title}' dicatat untuk pemagang {$internship->intern_id}.",
            'timestamp' => now(),
        ]);

        return $activity;
    }

    /**
     * Review Daily Activity
     */
    public function reviewDailyActivity(InternshipDailyActivity $activity, array $data, ?Admin $actor = null): InternshipDailyActivity
    {
        $activity->status = $data['status'] ?? 'REVIEWED';
        $activity->mentor_notes = $data['mentor_notes'] ?? $activity->mentor_notes;
        $activity->reviewed_by = $actor?->id;
        $activity->reviewed_at = now();
        $activity->save();

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'internship_activity_reviewed',
            'description' => "Aktivitas harian #{$activity->id} diverifikasi (Status: {$activity->status}).",
            'timestamp' => now(),
        ]);

        return $activity;
    }

    /**
     * Submit Monthly / Periodic Report
     */
    public function submitReport(Internship $internship, array $data, ?Admin $actor = null): InternshipReport
    {
        $report = InternshipReport::create([
            'internship_id' => $internship->id,
            'report_type' => $data['report_type'] ?? 'MONTHLY',
            'period_month' => $data['period_month'] ?? date('Y-m'),
            'title' => $data['title'],
            'summary' => $data['summary'],
            'achievements' => $data['achievements'] ?? null,
            'issues_and_blockers' => $data['issues_and_blockers'] ?? null,
            'intern_notes' => $data['intern_notes'] ?? null,
            'attachment_url' => $data['attachment_url'] ?? null,
            'status' => 'SUBMITTED',
        ]);

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'internship_report_submitted',
            'description' => "Laporan magang ({$report->report_type} - {$report->period_month}) diajukan untuk {$internship->intern_id}.",
            'timestamp' => now(),
        ]);

        return $report;
    }

    /**
     * Review Report
     */
    public function reviewReport(InternshipReport $report, array $data, ?Admin $actor = null): InternshipReport
    {
        $report->status = $data['status'] ?? 'APPROVED';
        $report->mentor_notes = $data['mentor_notes'] ?? $report->mentor_notes;
        $report->reviewed_by = $actor?->id;
        $report->reviewed_at = now();
        $report->save();

        ActivityLog::create([
            'admin_id' => $actor?->id,
            'action' => 'internship_report_reviewed',
            'description' => "Laporan magang #{$report->id} ditinjau (Status: {$report->status}).",
            'timestamp' => now(),
        ]);

        return $report;
    }

    /**
     * Record Internship Evaluation
     */
    public function recordEvaluation(Internship $internship, array $data, ?Admin $evaluator = null): InternshipEvaluation
    {
        $metrics = [
            'discipline_score' => (int) ($data['discipline_score'] ?? 80),
            'communication_score' => (int) ($data['communication_score'] ?? 80),
            'technical_score' => (int) ($data['technical_score'] ?? 80),
            'initiative_score' => (int) ($data['initiative_score'] ?? 80),
            'teamwork_score' => (int) ($data['teamwork_score'] ?? 80),
            'attendance_score' => (int) ($data['attendance_score'] ?? 80),
            'task_completion_score' => (int) ($data['task_completion_score'] ?? 80),
            'professionalism_score' => (int) ($data['professionalism_score'] ?? 80),
        ];

        $average = round(array_sum($metrics) / count($metrics), 2);

        $evaluation = InternshipEvaluation::create(array_merge($metrics, [
            'internship_id' => $internship->id,
            'evaluator_id' => $evaluator?->id,
            'evaluator_name' => $data['evaluator_name'] ?? $evaluator?->name ?? 'Pembimbing Lapangan',
            'evaluator_role' => $data['evaluator_role'] ?? 'Mentor',
            'evaluation_type' => $data['evaluation_type'] ?? 'FINAL',
            'average_score' => $average,
            'strengths' => $data['strengths'] ?? null,
            'improvements' => $data['improvements'] ?? null,
            'final_recommendation' => $data['final_recommendation'] ?? 'COMPLETE',
            'evaluated_at' => $data['evaluated_at'] ?? now()->toDateString(),
        ]));

        ActivityLog::create([
            'admin_id' => $evaluator?->id,
            'action' => 'internship_evaluation_recorded',
            'description' => "Evaluasi magang {$internship->intern_id} disimpan dengan nilai rata-rata {$average} ({$evaluation->final_recommendation}).",
            'timestamp' => now(),
        ]);

        return $evaluation;
    }

    /**
     * Get Internship Metrics Summary
     */
    public function getMetrics(): array
    {
        $activeInterns = Internship::where('status', 'ACTIVE')->count();
        $pendingReports = InternshipReport::where('status', 'SUBMITTED')->count();
        $completedCount = Internship::where('status', 'COMPLETED')->count();
        $mentorsCount = Internship::whereNotNull('mentor_id')->distinct('mentor_id')->count('mentor_id');
        $totalActivities = InternshipDailyActivity::count();

        return [
            'active_interns' => $activeInterns,
            'pending_reports' => $pendingReports,
            'completed_interns' => $completedCount,
            'assigned_mentors' => $mentorsCount,
            'total_activities' => $totalActivities,
        ];
    }
}
