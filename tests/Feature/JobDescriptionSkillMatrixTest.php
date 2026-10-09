<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\JobDescription;
use App\Models\Position;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JobDescriptionSkillMatrixTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_description_versions_are_lifecycle_managed_without_replacement(): void
    {
        $hrd = $this->admin('hrd');
        $position = Position::create(['code' => 'POS-13', 'name' => 'Security Engineer']);
        Sanctum::actingAs($hrd);

        $this->postJson('/api/v1/admin/job-descriptions', [
            'position_id' => $position->id,
            'title' => 'Security Engineer v1',
            'version' => 1,
            'content' => 'Operate access control systems.',
        ])->assertCreated();

        $this->postJson('/api/v1/admin/job-descriptions', [
            'position_id' => $position->id,
            'title' => 'Security Engineer v2',
            'version' => 2,
            'content' => 'Operate and audit access control systems.',
        ])->assertCreated();

        $this->assertDatabaseCount('job_descriptions', 2);
        $this->getJson('/api/v1/admin/job-descriptions')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_self_declared_skill_is_not_verified_and_self_verification_is_denied(): void
    {
        $employee = Employee::create(['employee_id' => 'EMP-13', 'nik' => 'NIK-13', 'name' => 'Skill Owner', 'department' => 'Security']);
        $employeeUser = $this->admin('employee', $employee->id);
        $skill = Skill::create(['code' => 'LARAVEL', 'name' => 'Laravel']);
        Sanctum::actingAs($employeeUser);

        $this->postJson("/api/v1/employees/{$employee->id}/skills", [
            'skill_id' => $skill->id,
            'declared_level' => 5,
        ])->assertCreated()->assertJsonPath('data.verified_level', null);

        $this->postJson("/api/v1/employees/{$employee->id}/skills/{$skill->id}/verify", [
            'verified_level' => 5,
        ])->assertForbidden();

        $this->assertDatabaseHas('employee_skills', ['employee_id' => $employee->id, 'verified_level' => null]);
    }

    public function test_skill_gap_uses_verified_level_only(): void
    {
        $hrd = $this->admin('hrd');
        $employee = Employee::create(['employee_id' => 'EMP-GAP', 'nik' => 'NIK-GAP', 'name' => 'Gap Owner', 'department' => 'Platform']);
        $position = Position::create(['code' => 'POS-GAP', 'name' => 'Platform Engineer']);
        $employee->update(['position_id' => $position->id]);
        $skill = Skill::create(['code' => 'PHP', 'name' => 'PHP']);
        Sanctum::actingAs($hrd);

        $jobDescription = JobDescription::create([
            'position_id' => $position->id,
            'title' => 'Platform Engineer',
            'version' => 1,
            'status' => 'PUBLISHED',
            'content' => 'Build platform services.',
        ]);
        $jobDescription->skillRequirements()->create(['skill_id' => $skill->id, 'required_level' => 4]);

        $employeeUser = $this->admin('employee', $employee->id);
        Sanctum::actingAs($employeeUser);
        $this->postJson("/api/v1/employees/{$employee->id}/skills", ['skill_id' => $skill->id, 'declared_level' => 5])->assertCreated();
        $this->getJson("/api/v1/employees/{$employee->id}/skill-gap")->assertOk()->assertJsonPath('data.gaps.0.verified_level', null)->assertJsonPath('data.gaps.0.meets_requirement', false);

        Sanctum::actingAs($hrd);
        $this->postJson("/api/v1/employees/{$employee->id}/skills/{$skill->id}/verify", ['verified_level' => 4])->assertOk();
        $this->getJson("/api/v1/employees/{$employee->id}/skill-gap")->assertOk()->assertJsonPath('data.gaps.0.verified_level', 4)->assertJsonPath('data.gaps.0.meets_requirement', true);
    }

    private function admin(string $role, ?int $employeeId = null): Admin
    {
        return Admin::create([
            'name' => strtoupper($role) . ' 13',
            'email' => $role . $employeeId . '@example.test',
            'password' => bcrypt('password'),
            'role' => $role,
            'employee_id' => $employeeId,
        ]);
    }
}
