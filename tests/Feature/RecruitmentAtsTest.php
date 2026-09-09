<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Building;
use App\Models\Candidate;
use App\Models\Division;
use App\Models\Employee;
use App\Models\JobApplication;
use App\Models\JobVacancy;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecruitmentAtsTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $hrd;
    protected Building $building;
    protected Division $division;
    protected Position $position;

    protected function setUp(): void
    {
        parent::setUp();

        $this->building = Building::create([
            'code' => 'BLD-A',
            'name' => 'Gedung A Kantor Utama',
        ]);

        $this->division = Division::create([
            'building_id' => $this->building->id,
            'code' => 'DIV-IT',
            'name' => 'Information Technology',
        ]);

        $this->position = Position::create([
            'division_id' => $this->division->id,
            'code' => 'POS-SE',
            'name' => 'Software Engineer',
        ]);

        $this->hrd = Admin::create([
            'name' => 'HR Specialist',
            'email' => 'hrd@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'hrd',
        ]);
    }

    public function test_hrd_can_create_and_list_job_vacancies(): void
    {
        Sanctum::actingAs($this->hrd);

        $response = $this->postJson('/api/v1/recruitment/vacancies', [
            'title' => 'Senior Backend Developer',
            'division_id' => $this->division->id,
            'position_id' => $this->position->id,
            'building_id' => $this->building->id,
            'employment_type' => 'FULL_TIME',
            'experience_level' => 'SENIOR',
            'quota' => 2,
            'salary_min' => 15000000,
            'salary_max' => 22000000,
            'description' => 'Bertanggung jawab atas arsitektur backend PKP SecureGate.',
            'requirements' => 'Pengalaman Laravel, Docker, dan Redis.',
            'status' => 'OPEN',
            'deadline' => now()->addDays(30)->toDateString(),
        ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'title' => 'Senior Backend Developer',
                    'employment_type' => 'FULL_TIME',
                    'experience_level' => 'SENIOR',
                ],
            ]);

        $this->assertDatabaseHas('job_vacancies', [
            'title' => 'Senior Backend Developer',
            'quota' => 2,
        ]);

        $listResponse = $this->getJson('/api/v1/recruitment/vacancies');
        $listResponse->assertStatus(200)
            ->assertJsonPath('data.total', 1);
    }

    public function test_can_register_candidate_and_submit_job_application(): void
    {
        Sanctum::actingAs($this->hrd);

        $vacancy = JobVacancy::create([
            'vacancy_code' => 'VAC-2026-001',
            'title' => 'Frontend Engineer',
            'division_id' => $this->division->id,
            'position_id' => $this->position->id,
            'employment_type' => 'FULL_TIME',
            'experience_level' => 'MID',
            'quota' => 1,
            'description' => 'Mengembangkan frontend Vue / Blade.',
            'status' => 'OPEN',
        ]);

        $candResponse = $this->postJson('/api/v1/recruitment/candidates', [
            'first_name' => 'Ahmad',
            'last_name' => 'Kurniawan',
            'email' => 'ahmad.kurniawan@example.com',
            'phone' => '081234567890',
            'national_id' => '3201123456780001',
            'current_company' => 'PT Tech Nusantara',
            'current_position' => 'Frontend Developer',
            'source' => 'LINKEDIN',
        ]);

        $candResponse->assertStatus(201);
        $candidateId = $candResponse->json('data.id');

        $appResponse = $this->postJson('/api/v1/recruitment/applications', [
            'job_vacancy_id' => $vacancy->id,
            'candidate_id' => $candidateId,
            'expected_salary' => 12000000,
            'notes' => 'Portofolio UI sangat rapi dan responsive.',
        ]);

        $appResponse->assertStatus(201)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'current_stage' => 'APPLIED',
                    'status' => 'ACTIVE',
                ],
            ]);

        $this->assertDatabaseHas('job_applications', [
            'candidate_id' => $candidateId,
            'job_vacancy_id' => $vacancy->id,
            'current_stage' => 'APPLIED',
        ]);
    }

    public function test_application_lifecycle_and_candidate_to_employee_conversion(): void
    {
        Sanctum::actingAs($this->hrd);

        $vacancy = JobVacancy::create([
            'vacancy_code' => 'VAC-2026-002',
            'title' => 'DevOps Specialist',
            'division_id' => $this->division->id,
            'position_id' => $this->position->id,
            'building_id' => $this->building->id,
            'employment_type' => 'FULL_TIME',
            'experience_level' => 'MID',
            'quota' => 1,
            'description' => 'Mengelola pipeline CI/CD dan container orchestration.',
            'status' => 'OPEN',
        ]);

        $candidate = Candidate::create([
            'candidate_no' => 'CND-2026-0001',
            'first_name' => 'Faisal',
            'last_name' => 'Rahman',
            'email' => 'faisal.rahman@example.com',
            'phone' => '081987654321',
            'national_id' => '3171012345670002',
            'source' => 'CAREER_SITE',
        ]);

        $application = JobApplication::create([
            'application_no' => 'APP-2026-0001',
            'job_vacancy_id' => $vacancy->id,
            'candidate_id' => $candidate->id,
            'current_stage' => 'APPLIED',
            'status' => 'ACTIVE',
            'applied_at' => now(),
        ]);

        // 1. Move to HR Interview
        $stageRes = $this->postJson("/api/v1/recruitment/applications/{$application->id}/stage", [
            'stage' => 'HR_INTERVIEW',
        ]);
        $stageRes->assertStatus(200)
            ->assertJsonPath('data.current_stage', 'HR_INTERVIEW');

        // 2. Schedule Interview
        $interviewRes = $this->postJson("/api/v1/recruitment/applications/{$application->id}/interview", [
            'stage_code' => 'HR_INTERVIEW',
            'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
            'duration_minutes' => 60,
            'location_or_link' => 'Ruang Meeting Gedung A / Google Meet',
        ]);
        $interviewRes->assertStatus(201);
        $interviewId = $interviewRes->json('data.id');

        // 3. Submit Interview Feedback
        $feedbackRes = $this->putJson("/api/v1/recruitment/interviews/{$interviewId}/feedback", [
            'feedback' => 'Kandidat menguasai CI/CD GitLab, Docker, dan Linux system administration.',
            'score' => 90,
            'recommendation' => 'PROCEED',
        ]);
        $feedbackRes->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPLETED');

        // 4. Create Offer
        $offerRes = $this->postJson("/api/v1/recruitment/applications/{$application->id}/offer", [
            'offered_salary' => 16000000,
            'start_date' => now()->addWeeks(2)->toDateString(),
            'expiry_date' => now()->addWeeks(3)->toDateString(),
            'terms' => 'Masa percobaan 3 bulan dengan evaluasi kinerja berkala.',
        ]);
        $offerRes->assertStatus(201)
            ->assertJsonPath('data.offered_salary', 16000000);

        // Application current_stage should now be OFFER
        $this->assertEquals('OFFER', $application->fresh()->current_stage);

        // 5. Convert Hired Candidate to Employee Master
        $convertRes = $this->postJson("/api/v1/recruitment/applications/{$application->id}/convert-to-employee", [
            'employment_status' => 'permanent',
        ]);

        $convertRes->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'name' => 'Faisal Rahman',
                    'email' => 'faisal.rahman@example.com',
                ],
            ]);

        $createdEmployeeId = $convertRes->json('data.id');

        // Verify Candidate link to Employee
        $this->assertEquals($createdEmployeeId, $candidate->fresh()->converted_employee_id);

        // Verify Application status is HIRED and stage is ACCEPTED
        $this->assertEquals('HIRED', $application->fresh()->status);
        $this->assertEquals('ACCEPTED', $application->fresh()->current_stage);

        // Verify Activity Log was recorded
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'candidate_converted_to_employee',
        ]);
    }

    public function test_recruitment_metrics_summary(): void
    {
        Sanctum::actingAs($this->hrd);

        $response = $this->getJson('/api/v1/recruitment/metrics');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'open_vacancies',
                    'total_candidates',
                    'active_applications',
                    'scheduled_interviews',
                    'hired_count',
                    'hire_rate_percentage',
                ],
            ]);
    }
}
