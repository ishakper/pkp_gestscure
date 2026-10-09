<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Building;
use App\Models\Candidate;
use App\Models\Division;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\JobApplication;
use App\Models\JobVacancy;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecruitmentRbacTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $superAdmin;
    protected Admin $hrd;
    protected Admin $supervisor;
    protected Admin $developer;
    protected Admin $employeeUser;
    protected Division $division;
    protected JobVacancy $vacancy;
    protected Candidate $candidate;
    protected JobApplication $application;
    protected Interview $interview;

    protected function setUp(): void
    {
        parent::setUp();

        $building = Building::create(['code' => 'BLD-A', 'name' => 'Gedung A']);
        $this->division = Division::create(['building_id' => $building->id, 'code' => 'DIV-ENG', 'name' => 'Engineering']);
        $position = Position::create(['division_id' => $this->division->id, 'code' => 'POS-DEV', 'name' => 'Developer']);

        $this->superAdmin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'admin@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->hrd = Admin::create([
            'name' => 'HRD Admin',
            'email' => 'hrd@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'hrd',
        ]);

        $supervisorEmployee = Employee::create([
            'employee_id' => 'EMP-SPV-01',
            'nik' => 'NIK-SPV-01',
            'name' => 'Supervisor Eng',
            'department' => 'Engineering',
            'division_id' => $this->division->id,
            'role_jabatan' => 'Engineering Lead',
        ]);

        $this->supervisor = Admin::create([
            'name' => 'Supervisor Eng',
            'email' => 'spv@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'supervisor',
            'employee_id' => $supervisorEmployee->id,
        ]);

        $this->developer = Admin::create([
            'name' => 'Technical Admin',
            'email' => 'dev@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'developer',
        ]);

        $this->employeeUser = Admin::create([
            'name' => 'Standard Employee',
            'email' => 'staff@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'employee',
        ]);

        $this->vacancy = JobVacancy::create([
            'vacancy_code' => 'VAC-2026-99',
            'title' => 'Backend Go Developer',
            'division_id' => $this->division->id,
            'position_id' => $position->id,
            'description' => 'Golang microservices engineer.',
        ]);

        $this->candidate = Candidate::create([
            'candidate_no' => 'CND-2026-99',
            'first_name' => 'Dimas',
            'email' => 'dimas@example.com',
            'phone' => '08111222333',
        ]);

        $this->application = JobApplication::create([
            'application_no' => 'APP-2026-99',
            'job_vacancy_id' => $this->vacancy->id,
            'candidate_id' => $this->candidate->id,
            'current_stage' => 'USER_INTERVIEW',
        ]);

        $this->interview = Interview::create([
            'job_application_id' => $this->application->id,
            'stage_code' => 'USER_INTERVIEW',
            'interviewer_id' => $this->supervisor->id,
            'scheduled_at' => now()->addDay(),
            'location_or_link' => 'Ruang Diskusi',
            'status' => 'SCHEDULED',
        ]);
    }

    public function test_super_admin_and_hrd_can_access_all_recruitment_endpoints(): void
    {
        Sanctum::actingAs($this->superAdmin);
        $this->getJson('/api/v1/recruitment/metrics')->assertStatus(200);
        $this->getJson('/api/v1/recruitment/vacancies')->assertStatus(200);
        $this->getJson('/api/v1/recruitment/candidates')->assertStatus(200);
        $this->getJson('/api/v1/recruitment/applications')->assertStatus(200);

        Sanctum::actingAs($this->hrd);
        $this->getJson('/api/v1/recruitment/metrics')->assertStatus(200);
        $this->getJson('/api/v1/recruitment/vacancies')->assertStatus(200);
        $this->getJson('/api/v1/recruitment/candidates')->assertStatus(200);
        $this->getJson('/api/v1/recruitment/applications')->assertStatus(200);
    }

    public function test_supervisor_has_scoped_access_and_cannot_create_vacancies(): void
    {
        Sanctum::actingAs($this->supervisor);

        // Supervisor can view applications in their division
        $this->getJson('/api/v1/recruitment/applications')->assertStatus(200);

        // Supervisor can submit interview feedback for their own assigned interview
        $feedbackRes = $this->putJson("/api/v1/recruitment/interviews/{$this->interview->id}/feedback", [
            'feedback' => 'Pemahaman arsitektur microservices sangat baik.',
            'score' => 95,
            'recommendation' => 'PROCEED',
        ]);
        $feedbackRes->assertStatus(200);

        // Supervisor CANNOT create job vacancies
        $this->postJson('/api/v1/recruitment/vacancies', [
            'title' => 'Illegal Vacancy',
            'employment_type' => 'FULL_TIME',
            'experience_level' => 'MID',
            'quota' => 1,
            'description' => 'Test',
        ])->assertStatus(403);

        // Supervisor CANNOT convert candidate to employee
        $this->postJson("/api/v1/recruitment/applications/{$this->application->id}/convert-to-employee", [])
            ->assertStatus(403);
    }

    public function test_developer_and_employee_are_denied_access_to_recruitment_data(): void
    {
        // Technical Admin (developer) denied to protect HR and candidate privacy
        Sanctum::actingAs($this->developer);
        $this->getJson('/api/v1/recruitment/metrics')->assertStatus(403);
        $this->getJson('/api/v1/recruitment/vacancies')->assertStatus(403);
        $this->getJson('/api/v1/recruitment/candidates')->assertStatus(403);
        $this->getJson('/api/v1/recruitment/applications')->assertStatus(403);

        // Standard employee denied
        Sanctum::actingAs($this->employeeUser);
        $this->getJson('/api/v1/recruitment/metrics')->assertStatus(403);
        $this->getJson('/api/v1/recruitment/vacancies')->assertStatus(403);
        $this->getJson('/api/v1/recruitment/candidates')->assertStatus(403);
        $this->getJson('/api/v1/recruitment/applications')->assertStatus(403);
    }
}
