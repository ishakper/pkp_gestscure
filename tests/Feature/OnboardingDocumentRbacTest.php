<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Division;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingDocumentRbacTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_super_admin_and_hrd_have_full_access(): void
    {
        $superAdmin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'super.admin@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $hrd = Admin::create([
            'name' => 'HRD Admin',
            'email' => 'hrd.admin@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-RBAC-001',
            'nik' => '3201010101010010',
            'name' => 'Karyawan Uji',
            'email' => 'uji@pkp.co.id',
            'department' => 'Operations',
            'role' => 'Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        // SuperAdmin can view metrics & cases
        Sanctum::actingAs($superAdmin);
        $this->getJson('/api/v1/onboarding/metrics')->assertStatus(200);
        $this->getJson('/api/v1/onboarding/cases')->assertStatus(200);
        $this->getJson('/api/v1/onboarding/contracts')->assertStatus(200);
        $this->getJson('/api/v1/onboarding/documents')->assertStatus(200);

        // HRD can view metrics & create case
        Sanctum::actingAs($hrd);
        $this->getJson('/api/v1/onboarding/metrics')->assertStatus(200);
        $this->postJson('/api/v1/onboarding/cases', [
            'employee_id' => $employee->id,
            'employment_type' => 'PERMANENT',
            'start_date' => Carbon::today()->toDateString(),
        ])->assertStatus(201);
    }

    public function test_supervisor_scoped_access_and_sensitive_document_denial(): void
    {
        $division = Division::create(['code' => 'ENG', 'name' => 'Engineering']);
        $otherDivision = Division::create(['code' => 'FIN', 'name' => 'Finance']);

        $supervisorEmp = Employee::create([
            'employee_id' => 'EMP-SUP-001',
            'nik' => '3201010101010020',
            'name' => 'Pak Supervisor',
            'email' => 'sup@pkp.co.id',
            'department' => 'Engineering',
            'role' => 'Supervisor',
            'division_id' => $division->id,
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        $supervisorAdmin = Admin::create([
            'name' => 'Pak Supervisor Admin',
            'email' => 'sup.admin@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'supervisor',
            'employee_id' => $supervisorEmp->id,
            'division_id' => $division->id,
        ]);

        $directReport = Employee::create([
            'employee_id' => 'EMP-DEV-001',
            'nik' => '3201010101010021',
            'name' => 'Programmer Junior',
            'email' => 'junior@pkp.co.id',
            'department' => 'Engineering',
            'role' => 'Developer',
            'supervisor_id' => $supervisorEmp->id,
            'division_id' => $division->id,
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        $unrelatedEmployee = Employee::create([
            'employee_id' => 'EMP-ACC-001',
            'nik' => '3201010101010022',
            'name' => 'Akuntan Kantor',
            'email' => 'acc@pkp.co.id',
            'department' => 'Finance',
            'role' => 'Accountant',
            'division_id' => $otherDivision->id,
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        // Upload an ASSIGNMENT document for direct report
        $storedPath = 'hr_documents/2026/assignment_test.pdf';
        Storage::disk('local')->put($storedPath, 'assignment content');

        $assignmentDoc = EmployeeDocument::create([
            'document_number' => 'DOC-2026-0001',
            'employee_id' => $directReport->id,
            'category' => 'ASSIGNMENT',
            'title' => 'Surat Tugas Project Alpha',
            'file_path' => $storedPath,
            'file_name' => 'assignment_test.pdf',
            'mime_type' => 'application/pdf',
            'visibility' => 'SUPERVISOR_SHARED',
            'status' => 'VERIFIED',
        ]);

        // Upload a MEDICAL document for direct report (confidential!)
        $medPath = 'hr_documents/2026/medical_test.pdf';
        Storage::disk('local')->put($medPath, 'medical record content');

        $medicalDoc = EmployeeDocument::create([
            'document_number' => 'DOC-2026-0002',
            'employee_id' => $directReport->id,
            'category' => 'MEDICAL',
            'title' => 'Hasil Medical Checkup Rahasia',
            'file_path' => $medPath,
            'file_name' => 'medical_test.pdf',
            'mime_type' => 'application/pdf',
            'visibility' => 'CONFIDENTIAL_HR',
            'status' => 'VERIFIED',
        ]);

        Sanctum::actingAs($supervisorAdmin);

        // Supervisor can download ASSIGNMENT document of direct report
        $resAssign = $this->getJson("/api/v1/onboarding/documents/{$assignmentDoc->id}/download");
        $resAssign->assertStatus(200);

        // Supervisor is DENIED (403) from downloading MEDICAL document
        $resMed = $this->getJson("/api/v1/onboarding/documents/{$medicalDoc->id}/download");
        $resMed->assertStatus(403);
    }

    public function test_employee_self_service_and_idor_prevention(): void
    {
        $emp1 = Employee::create([
            'employee_id' => 'EMP-USER-001',
            'nik' => '3201010101010031',
            'name' => 'Karyawan Pertama',
            'email' => 'emp1@pkp.co.id',
            'department' => 'Operations',
            'role' => 'Employee',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        $emp2 = Employee::create([
            'employee_id' => 'EMP-USER-002',
            'nik' => '3201010101010032',
            'name' => 'Karyawan Kedua',
            'email' => 'emp2@pkp.co.id',
            'department' => 'Operations',
            'role' => 'Employee',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        $admin1 = Admin::create([
            'name' => 'Employee 1 User',
            'email' => 'emp1.user@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'employee_id' => $emp1->id,
        ]);

        $path1 = 'hr_documents/2026/emp1_contract.pdf';
        $path2 = 'hr_documents/2026/emp2_contract.pdf';
        Storage::disk('local')->put($path1, 'emp1 contract content');
        Storage::disk('local')->put($path2, 'emp2 contract content');

        $docEmp1 = EmployeeDocument::create([
            'document_number' => 'DOC-2026-0010',
            'employee_id' => $emp1->id,
            'category' => 'CONTRACT',
            'title' => 'Kontrak Karyawan 1',
            'file_path' => $path1,
            'file_name' => 'emp1_contract.pdf',
            'mime_type' => 'application/pdf',
            'visibility' => 'EMPLOYEE_VISIBLE',
            'status' => 'VERIFIED',
        ]);

        $docEmp2 = EmployeeDocument::create([
            'document_number' => 'DOC-2026-0011',
            'employee_id' => $emp2->id,
            'category' => 'CONTRACT',
            'title' => 'Kontrak Karyawan 2',
            'file_path' => $path2,
            'file_name' => 'emp2_contract.pdf',
            'mime_type' => 'application/pdf',
            'visibility' => 'EMPLOYEE_VISIBLE',
            'status' => 'VERIFIED',
        ]);

        Sanctum::actingAs($admin1);

        // Employee 1 can download their own document
        $this->getJson("/api/v1/onboarding/documents/{$docEmp1->id}/download")->assertStatus(200);

        // Employee 1 CANNOT download Employee 2 document (IDOR prevented with 403)
        $this->getJson("/api/v1/onboarding/documents/{$docEmp2->id}/download")->assertStatus(403);
    }

    public function test_technical_and_building_admin_strictly_denied_from_hr_documents(): void
    {
        $dev = Admin::create([
            'name' => 'Developer User',
            'email' => 'dev.user@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'developer',
        ]);

        $devops = Admin::create([
            'name' => 'DevOps User',
            'email' => 'devops.user@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'devops',
        ]);

        $buildingAdmin = Admin::create([
            'name' => 'Building Admin User',
            'email' => 'bldg.user@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-TECH-001',
            'nik' => '3201010101010041',
            'name' => 'Staff Biasa',
            'email' => 'staff@pkp.co.id',
            'department' => 'Operations',
            'role' => 'Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        $storedPath = 'hr_documents/2026/sensitive_hr.pdf';
        Storage::disk('local')->put($storedPath, 'sensitive HR confidential');

        $doc = EmployeeDocument::create([
            'document_number' => 'DOC-2026-0099',
            'employee_id' => $employee->id,
            'category' => 'CONTRACT',
            'title' => 'Kontrak Gaji dan Benefit Rahasia',
            'file_path' => $storedPath,
            'file_name' => 'sensitive_hr.pdf',
            'mime_type' => 'application/pdf',
            'visibility' => 'CONFIDENTIAL_HR',
            'status' => 'VERIFIED',
        ]);

        // Developer denied
        Sanctum::actingAs($dev);
        $this->getJson('/api/v1/onboarding/metrics')->assertStatus(403);
        $this->getJson('/api/v1/onboarding/cases')->assertStatus(403);
        $this->getJson('/api/v1/onboarding/contracts')->assertStatus(403);
        $this->getJson("/api/v1/onboarding/documents/{$doc->id}/download")->assertStatus(403);

        // DevOps denied
        Sanctum::actingAs($devops);
        $this->getJson('/api/v1/onboarding/metrics')->assertStatus(403);
        $this->getJson("/api/v1/onboarding/documents/{$doc->id}/download")->assertStatus(403);

        // Building Admin denied
        Sanctum::actingAs($buildingAdmin);
        $this->getJson('/api/v1/onboarding/metrics')->assertStatus(403);
        $this->getJson('/api/v1/onboarding/cases')->assertStatus(403);
        $this->getJson('/api/v1/onboarding/contracts')->assertStatus(403);
        $this->getJson("/api/v1/onboarding/documents/{$doc->id}/download")->assertStatus(403);
    }
}
