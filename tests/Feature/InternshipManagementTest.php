<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Candidate;
use App\Models\Employee;
use App\Models\Internship;
use App\Models\InternshipDailyActivity;
use App\Models\InternshipEvaluation;
use App\Models\InternshipReport;
use App\Models\JobApplication;
use App\Models\JobVacancy;
use App\Models\RecruitmentStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InternshipManagementTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $superAdmin;
    protected Admin $hrdAdmin;
    protected Employee $mentor;
    protected string $superAdminEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminEmail = 'test_'.uniqid().'@accesscontrol.local';
        $this->superAdmin = Admin::create([
            'name' => 'Super Administrator',
            'email' => $this->superAdminEmail,
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $this->hrdAdmin = Admin::create([
            'name' => 'HRD Lead',
            'email' => 'hrd@accesscontrol.local',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $this->mentor = Employee::create([
            'employee_id' => 'EMP-SENIOR-001',
            'nik' => 'NIK-882001',
            'name' => 'Senior Engineer Mentor',
            'email' => 'mentor@pkp.co.id',
            'role' => 'Tech Lead',
            'role_jabatan' => 'Tech Lead',
            'department' => 'IT & Engineering',
            'employment_status' => 'active',
        ]);
    }

    public function test_can_create_and_list_internships(): void
    {
        $payload = [
            'institution' => 'Institut Teknologi Bandung',
            'major' => 'Teknik Informatika',
            'education_level' => 'S1',
            'semester' => 7,
            'start_date' => '2026-10-01',
            'end_date' => '2027-01-31',
            'position_title' => 'Software Engineer Intern',
            'mentor_id' => $this->mentor->id,
            'campus_supervisor_name' => 'Dr. Ir. Hendra',
        ];

        $response = $this->actingAs($this->superAdmin)->postJson('/api/v1/internships', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.institution', 'Institut Teknologi Bandung')
            ->assertJsonPath('data.mentor_id', $this->mentor->id);

        $this->assertDatabaseHas('internships', [
            'institution' => 'Institut Teknologi Bandung',
            'position_title' => 'Software Engineer Intern',
            'mentor_id' => $this->mentor->id,
        ]);

        $listResp = $this->actingAs($this->superAdmin)->getJson('/api/v1/internships');
        $listResp->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonCount(1, 'data');
    }

    public function test_candidate_to_intern_conversion(): void
    {
        $candidate = Candidate::create([
            'candidate_no' => 'CAND-202609-0010',
            'first_name' => 'Budi',
            'last_name' => 'Santoso',
            'email' => 'budi.intern@campus.ac.id',
            'phone' => '081299998888',
            'national_id' => '3201009988776655',
            'source' => 'JOB_FAIR',
        ]);

        $payload = [
            'candidate_id' => $candidate->id,
            'institution' => 'Universitas Gadjah Mada',
            'major' => 'Ilmu Komputer',
            'education_level' => 'S1',
            'semester' => 6,
            'start_date' => '2026-10-01',
            'end_date' => '2027-02-28',
            'position_title' => 'Backend Intern',
            'mentor_id' => $this->mentor->id,
        ];

        $response = $this->actingAs($this->hrdAdmin)->postJson('/api/v1/internships/convert-candidate', $payload);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.candidate_id', $candidate->id)
            ->assertJsonPath('data.institution', 'Universitas Gadjah Mada');

        // Verify Employee was created with INTERNSHIP type
        $this->assertDatabaseHas('employees', [
            'name' => 'Budi Santoso',
            'email' => 'budi.intern@campus.ac.id',
            'employment_type' => 'INTERNSHIP',
            'supervisor_id' => $this->mentor->id,
        ]);

        // Verify Candidate link
        $candidate->refresh();
        $this->assertNotNull($candidate->converted_employee_id);
    }

    public function test_mentor_validation_rejects_self_assignment_and_invalid_employee(): void
    {
        // 1. Invalid employee ID
        $payload = [
            'institution' => 'UI',
            'major' => 'Sistem Informasi',
            'start_date' => '2026-10-01',
            'end_date' => '2027-01-31',
            'mentor_id' => 999999, // nonexistent
        ];

        $resp = $this->actingAs($this->superAdmin)->postJson('/api/v1/internships', $payload);
        $resp->assertStatus(422);

        // 2. Self assignment
        $internEmployee = Employee::create([
            'employee_id' => 'EMP-INT-001',
            'nik' => 'NIK-330101',
            'name' => 'Self Intern',
            'email' => 'self@campus.ac.id',
            'department' => 'Magang',
        ]);

        $selfPayload = [
            'employee_id' => $internEmployee->id,
            'institution' => 'UI',
            'major' => 'Sistem Informasi',
            'start_date' => '2026-10-01',
            'end_date' => '2027-01-31',
            'mentor_id' => $internEmployee->id, // Self mentor
        ];

        $selfResp = $this->actingAs($this->superAdmin)->postJson('/api/v1/internships', $selfPayload);
        $selfResp->assertStatus(422);
    }

    public function test_daily_activities_and_review_lifecycle(): void
    {
        $internship = Internship::create([
            'intern_id' => 'INT-2026-0001',
            'institution' => 'Politeknik Negeri Bandung',
            'major' => 'Teknik Komputer',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
            'mentor_id' => $this->mentor->id,
        ]);

        // Intern logs activity
        $actPayload = [
            'activity_date' => '2026-09-10',
            'title' => 'Mempelajari arsitektur Laravel ISAPI Service',
            'description' => 'Melakukan refactoring controller dan setup unit test mock.',
            'start_time' => '09:00',
            'end_time' => '17:30',
            'progress_percent' => 100,
        ];

        $actResp = $this->actingAs($this->superAdmin)->postJson("/api/v1/internships/{$internship->id}/activities", $actPayload);
        $actResp->assertStatus(201)
            ->assertJsonPath('data.title', 'Mempelajari arsitektur Laravel ISAPI Service')
            ->assertJsonPath('data.status', 'SUBMITTED');

        $activityId = $actResp->json('data.id');

        // Review activity
        $revPayload = [
            'status' => 'REVIEWED',
            'mentor_notes' => 'Pekerjaan rapi dan sesuai standar coding enterprise PKP.',
        ];

        $revResp = $this->actingAs($this->superAdmin)->putJson("/api/v1/internships/activities/{$activityId}/review", $revPayload);
        $revResp->assertStatus(200)
            ->assertJsonPath('data.status', 'REVIEWED')
            ->assertJsonPath('data.mentor_notes', 'Pekerjaan rapi dan sesuai standar coding enterprise PKP.');
    }

    public function test_monthly_reports_and_evaluations_and_completion(): void
    {
        $internship = Internship::create([
            'intern_id' => 'INT-2026-0002',
            'institution' => 'Universitas Indonesia',
            'major' => 'Sistem Informasi',
            'start_date' => '2026-06-01',
            'end_date' => '2026-09-30',
            'status' => 'ACTIVE',
            'mentor_id' => $this->mentor->id,
        ]);

        // 1. Submit Monthly Report
        $reportPayload = [
            'report_type' => 'FINAL',
            'period_month' => '2026-09',
            'title' => 'Laporan Akhir Magang Pengembangan SecureGate ATS',
            'summary' => 'Berhasil membangun modul rekrutmen dan magang.',
            'achievements' => 'Menyelesaikan 100% target backlog.',
            'issues_and_blockers' => 'Tidak ada blocker kritis.',
        ];

        $repResp = $this->actingAs($this->superAdmin)->postJson("/api/v1/internships/{$internship->id}/reports", $reportPayload);
        $repResp->assertStatus(201);
        $reportId = $repResp->json('data.id');

        // Review Report
        $revRepResp = $this->actingAs($this->hrdAdmin)->putJson("/api/v1/internships/reports/{$reportId}/review", [
            'status' => 'APPROVED',
            'mentor_notes' => 'Laporan komprehensif dan disetujui.',
        ]);
        $revRepResp->assertStatus(200)->assertJsonPath('data.status', 'APPROVED');

        // 2. Submit Final Evaluation
        $evalPayload = [
            'evaluation_type' => 'FINAL',
            'discipline_score' => 90,
            'communication_score' => 85,
            'technical_score' => 95,
            'initiative_score' => 90,
            'teamwork_score' => 88,
            'attendance_score' => 100,
            'task_completion_score' => 95,
            'professionalism_score' => 90,
            'strengths' => 'Kemampuan problem solving cepat dan adaptif.',
            'improvements' => 'Pertahankan konsistensi dokumentasi.',
            'final_recommendation' => 'HIRE_AS_EMPLOYEE',
        ];

        $evalResp = $this->actingAs($this->superAdmin)->postJson("/api/v1/internships/{$internship->id}/evaluations", $evalPayload);
        $evalResp->assertStatus(201)
            ->assertJsonPath('data.final_recommendation', 'HIRE_AS_EMPLOYEE');

        // 3. Complete Internship
        $compResp = $this->actingAs($this->hrdAdmin)->postJson("/api/v1/internships/{$internship->id}/complete", [
            'completion_notes' => 'Telah menyelesaikan seluruh masa magang dengan predikat Sangat Memuaskan.',
            'certificate_no' => 'CERT-INT-2026-0001',
        ]);

        $compResp->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.certificate_no', 'CERT-INT-2026-0001')
            ->assertJsonPath('data.access_revocation_marked', true);
    }
}
