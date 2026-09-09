<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OnboardingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_can_create_onboarding_case_and_seed_checklist(): void
    {
        $hrd = Admin::create([
            'name' => 'HRD Officer',
            'email' => 'hrd.test1@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-TEST-001',
            'nik' => '3201010101010001',
            'name' => 'Budi Santoso',
            'email' => 'budi@pkp.co.id',
            'department' => 'Technology',
            'role' => 'Software Engineer',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        Sanctum::actingAs($hrd);

        $payload = [
            'employee_id' => $employee->id,
            'employment_type' => 'PERMANENT',
            'start_date' => Carbon::today()->toDateString(),
            'work_location' => 'Kantor Pusat PKP',
            'notes' => 'Onboarding karyawan baru divisi IT.',
        ];

        $res = $this->postJson('/api/v1/onboarding/cases', $payload);
        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.employee_id', $employee->id)
            ->assertJsonPath('data.status', 'PENDING');

        $caseId = $res->json('data.id');
        $this->assertDatabaseHas('onboarding_cases', [
            'id' => $caseId,
            'employee_id' => $employee->id,
            'status' => 'PENDING',
        ]);

        // Verify standard 10 checklist tasks were created
        $tasksCount = OnboardingTask::where('onboarding_case_id', $caseId)->count();
        $this->assertEquals(10, $tasksCount);

        // Verify detail endpoint
        $detailRes = $this->getJson("/api/v1/onboarding/cases/{$caseId}");
        $detailRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('progress', 0);
    }

    public function test_onboarding_task_lifecycle_and_completion_gate(): void
    {
        $hrd = Admin::create([
            'name' => 'HRD Officer 2',
            'email' => 'hrd.test2@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-TEST-002',
            'nik' => '3201010101010002',
            'name' => 'Siti Aminah',
            'email' => 'siti@pkp.co.id',
            'department' => 'Design',
            'role' => 'UI Designer',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        Sanctum::actingAs($hrd);

        // 1. Create Case
        $createRes = $this->postJson('/api/v1/onboarding/cases', [
            'employee_id' => $employee->id,
            'employment_type' => 'PERMANENT',
            'start_date' => Carbon::today()->toDateString(),
        ]);
        $caseId = $createRes->json('data.id');

        // 2. Attempt completion while tasks are PENDING -> should fail with 422
        $completeFail = $this->postJson("/api/v1/onboarding/cases/{$caseId}/complete");
        $completeFail->assertStatus(422)
            ->assertJsonValidationErrors(['case']);

        // 3. Mark one task as BLOCKED
        $firstTask = OnboardingTask::where('onboarding_case_id', $caseId)->first();
        $blockRes = $this->putJson("/api/v1/onboarding/tasks/{$firstTask->id}", [
            'status' => 'BLOCKED',
            'blocker_reason' => 'Menunggu KTP asli dari calon karyawan',
        ]);
        $blockRes->assertStatus(200)
            ->assertJsonPath('case_status', 'BLOCKED');

        // Attempt completion with BLOCKED task -> should fail with 422
        $blockedFail = $this->postJson("/api/v1/onboarding/cases/{$caseId}/complete");
        $blockedFail->assertStatus(422)
            ->assertJsonValidationErrors(['case']);

        // 4. Complete all tasks
        $tasks = OnboardingTask::where('onboarding_case_id', $caseId)->get();
        foreach ($tasks as $t) {
            $this->putJson("/api/v1/onboarding/tasks/{$t->id}", [
                'status' => 'COMPLETED',
            ])->assertStatus(200);
        }

        // 5. Complete Case -> should succeed
        $completeSuccess = $this->postJson("/api/v1/onboarding/cases/{$caseId}/complete");
        $completeSuccess->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertDatabaseHas('onboarding_cases', [
            'id' => $caseId,
            'status' => 'COMPLETED',
            'completed_by' => $hrd->id,
        ]);
    }

    public function test_contract_management_lifecycle(): void
    {
        $hrd = Admin::create([
            'name' => 'HRD Officer 3',
            'email' => 'hrd.test3@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-TEST-003',
            'nik' => '3201010101010003',
            'name' => 'Ahmad Fauzi',
            'email' => 'ahmad@pkp.co.id',
            'department' => 'Technology',
            'role' => 'Backend Engineer',
            'employment_type' => 'FIXED_TERM',
            'employment_status' => 'active',
        ]);

        Sanctum::actingAs($hrd);

        // Create Contract
        $res = $this->postJson('/api/v1/onboarding/contracts', [
            'employee_id' => $employee->id,
            'contract_type' => 'FIXED_TERM',
            'title' => 'Perjanjian Kerja Waktu Tertentu (PKWT)',
            'start_date' => Carbon::today()->toDateString(),
            'end_date' => Carbon::today()->addDays(20)->toDateString(), // expiring in 20 days
            'status' => 'ACTIVE',
            'signed_date' => Carbon::today()->toDateString(),
            'signed_by_employee' => 'Ahmad Fauzi',
            'signed_by_company' => 'HR Director',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.contract_type', 'FIXED_TERM');

        $contractId = $res->json('data.id');

        // Update Contract
        $updRes = $this->putJson("/api/v1/onboarding/contracts/{$contractId}", [
            'renewal_status' => 'ELIGIBLE',
            'notes' => 'Memenuhi syarat untuk perpanjangan kontrak tahun berikutnya.',
        ]);
        $updRes->assertStatus(200)
            ->assertJsonPath('data.renewal_status', 'ELIGIBLE');

        // Check expiring contracts query
        $expRes = $this->getJson('/api/v1/onboarding/contracts?expiring=true&days=30');
        $expRes->assertStatus(200)
            ->assertJsonPath('pagination.total', 1);
    }

    public function test_document_upload_verification_and_acknowledgement(): void
    {
        $hrd = Admin::create([
            'name' => 'HRD Officer 4',
            'email' => 'hrd.test4@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-TEST-004',
            'nik' => '3201010101010004',
            'name' => 'Rina Wijaya',
            'email' => 'rina@pkp.co.id',
            'department' => 'Technology',
            'role' => 'QA Analyst',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'active',
        ]);

        Sanctum::actingAs($hrd);

        // Upload PDF Document
        $file = UploadedFile::fake()->create('contract_rina.pdf', 500, 'application/pdf');

        $uploadRes = $this->postJson('/api/v1/onboarding/documents', [
            'file' => $file,
            'category' => 'CONTRACT',
            'title' => 'Surat Kontrak Kerja Rina Wijaya',
            'employee_id' => $employee->id,
            'visibility' => 'EMPLOYEE_VISIBLE',
        ]);

        $uploadRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.category', 'CONTRACT')
            ->assertJsonPath('data.status', 'PENDING_VERIFICATION')
            ->assertJsonPath('data.version', 1);

        $docId = $uploadRes->json('data.id');
        $filePath = $uploadRes->json('data.file_path');

        // Verify file stored in private disk
        Storage::disk('local')->assertExists($filePath);

        // Verify Document
        $verRes = $this->putJson("/api/v1/onboarding/documents/{$docId}/verify", [
            'status' => 'VERIFIED',
            'notes' => 'Dokumen asli telah dicek dan valid.',
        ]);
        $verRes->assertStatus(200)
            ->assertJsonPath('data.status', 'VERIFIED');

        // Acknowledge Document
        $ackRes = $this->postJson("/api/v1/onboarding/documents/{$docId}/acknowledge", [
            'employee_id' => $employee->id,
            'notes' => 'Saya telah membaca dan menyetujui kontrak kerja ini.',
        ]);
        $ackRes->assertStatus(200)
            ->assertJsonPath('success', true);

        // Versioning: upload new version replacing parent
        $replacementFile = UploadedFile::fake()->create('contract_rina_v2.pdf', 600, 'application/pdf');
        $replaceRes = $this->postJson('/api/v1/onboarding/documents', [
            'file' => $replacementFile,
            'category' => 'CONTRACT',
            'title' => 'Adendum Kontrak Kerja Rina Wijaya (v2)',
            'employee_id' => $employee->id,
            'parent_document_id' => $docId,
            'visibility' => 'EMPLOYEE_VISIBLE',
        ]);

        $replaceRes->assertStatus(201)
            ->assertJsonPath('data.version', 2);

        // Parent should now be ARCHIVED
        $this->assertDatabaseHas('employee_documents', [
            'id' => $docId,
            'status' => 'ARCHIVED',
        ]);
    }
}
