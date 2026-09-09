<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\Internship;
use App\Models\InternshipDailyActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InternshipRbacTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $superAdmin;
    protected Admin $hrdAdmin;
    protected Admin $supervisorAdmin;
    protected Admin $developerAdmin;
    protected Admin $buildingAdmin;
    protected Internship $internshipA;
    protected Internship $internshipB;

    protected function setUp(): void
    {
        parent::setUp();

        $divisionA = \App\Models\Division::create(['name' => 'IT & Engineering', 'code' => 'IT']);
        $divisionB = \App\Models\Division::create(['name' => 'Operations', 'code' => 'OPS']);

        $mentorEmployee = Employee::create([
            'employee_id' => 'EMP-SPV-01',
            'nik' => 'NIK-881100',
            'name' => 'IT Supervisor Mentor',
            'email' => 'spv.mentor@pkp.co.id',
            'role' => 'Supervisor',
            'role_jabatan' => 'Supervisor',
            'department' => 'IT',
            'division_id' => $divisionA->id,
            'employment_status' => 'active',
        ]);

        $this->superAdmin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'super@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $this->hrdAdmin = Admin::create([
            'name' => 'HRD Specialist',
            'email' => 'hrd@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $this->supervisorAdmin = Admin::create([
            'name' => 'IT Supervisor',
            'email' => 'spv@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'supervisor',
            'employee_id' => $mentorEmployee->id,
        ]);

        $this->developerAdmin = Admin::create([
            'name' => 'Backend Developer',
            'email' => 'dev@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'developer',
        ]);

        $this->buildingAdmin = Admin::create([
            'name' => 'Admin Gedung B',
            'email' => 'bld@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
        ]);

        $this->internshipA = Internship::create([
            'intern_id' => 'INT-2026-0001',
            'institution' => 'Universitas Indonesia',
            'major' => 'Ilmu Komputer',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'mentor_id' => $mentorEmployee->id,
            'supervisor_id' => $mentorEmployee->id,
            'division_id' => $divisionA->id,
            'status' => 'ACTIVE',
        ]);

        $this->internshipB = Internship::create([
            'intern_id' => 'INT-2026-0002',
            'institution' => 'Institut Pertanian Bogor',
            'major' => 'Agronomi',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'mentor_id' => null,
            'division_id' => $divisionB->id,
            'status' => 'ACTIVE',
        ]);
    }

    public function test_super_admin_and_hrd_have_full_access(): void
    {
        $respA = $this->actingAs($this->superAdmin)->getJson('/api/v1/internships');
        $respA->assertStatus(200)->assertJsonCount(2, 'data');

        $respB = $this->actingAs($this->hrdAdmin)->getJson('/api/v1/internships');
        $respB->assertStatus(200)->assertJsonCount(2, 'data');

        $metricsResp = $this->actingAs($this->hrdAdmin)->getJson('/api/v1/internships/metrics');
        $metricsResp->assertStatus(200)->assertJsonPath('data.active_interns', 2);
    }

    public function test_supervisor_has_scoped_access_and_cannot_create_or_delete(): void
    {
        // 1. Can view assigned intern
        $respViewOwn = $this->actingAs($this->supervisorAdmin)->getJson("/api/v1/internships/{$this->internshipA->id}");
        $respViewOwn->assertStatus(200);

        // 2. Denied from viewing unrelated intern outside division
        $respViewOther = $this->actingAs($this->supervisorAdmin)->getJson("/api/v1/internships/{$this->internshipB->id}");
        $respViewOther->assertStatus(403);

        // 3. Supervisor cannot create new internships
        $createResp = $this->actingAs($this->supervisorAdmin)->postJson('/api/v1/internships', [
            'institution' => 'UNPAD',
            'major' => 'Hukum',
            'start_date' => '2026-10-01',
            'end_date' => '2027-01-31',
        ]);
        $createResp->assertStatus(403);
    }

    public function test_technical_and_building_admin_denied_from_internship_pii(): void
    {
        // Developer role denied
        $devResp = $this->actingAs($this->developerAdmin)->getJson('/api/v1/internships');
        $devResp->assertStatus(403);

        $devShow = $this->actingAs($this->developerAdmin)->getJson("/api/v1/internships/{$this->internshipA->id}");
        $devShow->assertStatus(403);

        // Building Admin denied
        $bldResp = $this->actingAs($this->buildingAdmin)->getJson('/api/v1/internships');
        $bldResp->assertStatus(403);

        $bldShow = $this->actingAs($this->buildingAdmin)->getJson("/api/v1/internships/{$this->internshipA->id}");
        $bldShow->assertStatus(403);
    }
}
