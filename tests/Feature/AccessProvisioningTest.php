<?php

namespace Tests\Feature;

use App\Models\AccessProfile;
use App\Models\AccessRequest;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Building;
use App\Models\CredentialDeviceSync;
use App\Models\CredentialRecord;
use App\Models\Door;
use App\Models\Employee;
use App\Models\Internship;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccessProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected Door $doorA;
    protected Door $doorB;
    protected Door $doorC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doorA = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'name' => 'Pintu Lobby Utama',
            'door_name' => 'Pintu Lobby Utama',
            'location' => 'Kantor Pusat PKP',
            'ip_address' => '192.168.1.50',
            'status' => 'online',
            'connection_status' => 'online',
        ]);

        $this->doorB = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'name' => 'Pintu Server Room',
            'door_name' => 'Pintu Server Room',
            'location' => 'Kantor Pusat PKP',
            'ip_address' => '192.168.1.51',
            'status' => 'online',
            'connection_status' => 'online',
        ]);

        $this->doorC = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'name' => 'Pintu Cabang Surabaya',
            'door_name' => 'Pintu Cabang Surabaya',
            'location' => 'Gedung Cabang Surabaya',
            'ip_address' => '192.168.2.50',
            'status' => 'online',
            'connection_status' => 'online',
        ]);
    }

    public function test_can_list_and_create_access_profiles(): void
    {
        $superadmin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'super@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        Sanctum::actingAs($superadmin);

        // Fetch profiles - auto seeds standard enterprise profiles
        $res = $this->getJson('/api/v1/access/profiles');
        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('access_profiles', [
            'code' => 'OFFICE_STANDARD',
        ]);

        // Create custom profile
        $createRes = $this->postJson('/api/v1/access/profiles', [
            'code' => 'FINANCE_RESTRICTED',
            'name' => 'Akses Ruang Keuangan Terbatas',
            'description' => 'Akses staf finance ke brankas',
            'building_name' => 'Kantor Pusat PKP',
            'allowed_doors' => [$this->doorA->door_id, $this->doorB->door_id],
            'schedule_type' => 'BUSINESS_HOURS',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'is_active' => true,
        ]);

        $createRes->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'FINANCE_RESTRICTED');

        $this->assertDatabaseHas('access_profiles', [
            'code' => 'FINANCE_RESTRICTED',
        ]);
    }

    public function test_can_submit_access_request(): void
    {
        $hrd = Admin::create([
            'name' => 'HR Officer',
            'email' => 'hrd@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-1001',
            'nik' => '3201010101010001',
            'name' => 'Rina Wijaya',
            'email' => 'rina@pkp.co.id',
            'department' => 'Finance',
            'role' => 'Finance Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($hrd);

        $res = $this->postJson('/api/v1/access/requests', [
            'employee_id' => $employee->id,
            'business_reason' => 'Membutuhkan akses harian ke kantor pusat untuk keperluan pembukuan',
            'building_name' => 'Kantor Pusat PKP',
            'specific_doors' => ['DOOR-A'],
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'PENDING_APPROVAL');

        $this->assertDatabaseHas('access_requests', [
            'employee_id' => $employee->id,
            'building_name' => 'Kantor Pusat PKP',
            'status' => 'PENDING_APPROVAL',
        ]);
    }

    public function test_building_admin_can_approve_same_building_request_and_triggers_device_sync(): void
    {
        $bldAdmin = Admin::create([
            'name' => 'Building Admin Pusat',
            'email' => 'building.pusat@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Kantor Pusat PKP',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-1002',
            'nik' => '3201010101010002',
            'name' => 'Ahmad Dani',
            'email' => 'ahmad@pkp.co.id',
            'department' => 'IT',
            'role' => 'Network Engineer',
            'card_no' => '9876543210',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        $request = AccessRequest::create([
            'request_number' => 'REQ-2026-0001',
            'employee_id' => $employee->id,
            'building_name' => 'Kantor Pusat PKP',
            'specific_doors' => [$this->doorA->door_id, $this->doorB->door_id],
            'business_reason' => 'Perawatan jaringan server',
            'status' => 'PENDING_APPROVAL',
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        Sanctum::actingAs($bldAdmin);

        $res = $this->postJson("/api/v1/access/requests/{$request->id}/approve", [
            'notes' => 'Disetujui untuk maintenance perangkat',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        // Check request transitioned to PROVISIONING / APPROVED
        $this->assertContains($request->fresh()->status, ['APPROVED', 'PROVISIONING']);

        // Check credential was automatically created
        $this->assertDatabaseHas('credential_records', [
            'employee_id' => $employee->id,
            'status' => 'ACTIVE',
        ]);

        // Check device sync queue was created for DOOR-A and DOOR-B
        $this->assertDatabaseHas('credential_device_syncs', [
            'door_id' => $this->doorA->id,
            'status' => 'QUEUED',
            'operation' => 'ADD',
        ]);
        $this->assertDatabaseHas('credential_device_syncs', [
            'door_id' => $this->doorB->id,
            'status' => 'QUEUED',
            'operation' => 'ADD',
        ]);
    }

    public function test_building_admin_denied_cross_building_request_approval(): void
    {
        $bldAdminPusat = Admin::create([
            'name' => 'Building Admin Pusat',
            'email' => 'building.pusat2@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Kantor Pusat PKP',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-1003',
            'nik' => '3201010101010003',
            'name' => 'Siti Surabaya',
            'email' => 'siti@pkp.co.id',
            'department' => 'Operations',
            'role' => 'Branch Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        // Access request for Surabaya branch
        $surabayaRequest = AccessRequest::create([
            'request_number' => 'REQ-2026-0002',
            'employee_id' => $employee->id,
            'building_name' => 'Gedung Cabang Surabaya',
            'specific_doors' => ['DOOR-C'],
            'business_reason' => 'Akses cabang surabaya',
            'status' => 'PENDING_APPROVAL',
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        Sanctum::actingAs($bldAdminPusat);

        // Attempting cross-building approval must result in 403 Forbidden
        $res = $this->postJson("/api/v1/access/requests/{$surabayaRequest->id}/approve");
        $res->assertStatus(403);

        $this->assertEquals('PENDING_APPROVAL', $surabayaRequest->fresh()->status);
    }

    public function test_employee_self_service_can_view_own_request_and_cross_employee_denied(): void
    {
        $emp1 = Employee::create([
            'employee_id' => 'EMP-DONI',
            'nik' => '3201010101010091',
            'name' => 'Doni Pegawai',
            'email' => 'doni@pkp.co.id',
            'department' => 'IT',
            'role' => 'Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        $emp2 = Employee::create([
            'employee_id' => 'EMP-FERRY',
            'nik' => '3201010101010092',
            'name' => 'Ferry Pegawai Lain',
            'email' => 'ferry@pkp.co.id',
            'department' => 'IT',
            'role' => 'Staff',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        $employeeAdmin = Admin::create([
            'name' => 'Doni Pegawai',
            'email' => 'doni@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'employee_id' => $emp1->id,
        ]);

        $otherEmployeeAdmin = Admin::create([
            'name' => 'Ferry Pegawai Lain',
            'email' => 'ferry@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'employee_id' => $emp2->id,
        ]);

        $ownRequest = AccessRequest::create([
            'request_number' => 'REQ-2026-0003',
            'employee_id' => $emp1->id,
            'building_name' => 'Kantor Pusat PKP',
            'business_reason' => 'Pengajuan akses pribadi',
            'status' => 'PENDING_APPROVAL',
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $otherRequest = AccessRequest::create([
            'request_number' => 'REQ-2026-0004',
            'employee_id' => $emp2->id,
            'building_name' => 'Kantor Pusat PKP',
            'business_reason' => 'Pengajuan akses orang lain',
            'status' => 'PENDING_APPROVAL',
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        Sanctum::actingAs($employeeAdmin);

        // Can view own request
        $ownRes = $this->getJson("/api/v1/access/requests/{$ownRequest->id}");
        $ownRes->assertStatus(200)
            ->assertJsonPath('data.request_number', 'REQ-2026-0003');

        // Cannot view other employee's request (IDOR denied)
        $otherRes = $this->getJson("/api/v1/access/requests/{$otherRequest->id}");
        $otherRes->assertStatus(403);
    }

    public function test_rejection_of_access_request_stores_reason_and_audits(): void
    {
        $superadmin = Admin::create([
            'name' => 'Security Lead',
            'email' => 'sec.lead@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $req = AccessRequest::create([
            'request_number' => 'REQ-2026-0005',
            'building_name' => 'Kantor Pusat PKP',
            'business_reason' => 'Akses server darurat tanpa tiket incident',
            'status' => 'PENDING_APPROVAL',
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        Sanctum::actingAs($superadmin);

        $res = $this->postJson("/api/v1/access/requests/{$req->id}/reject", [
            'reason' => 'Tidak disertakan nomor tiket incident IT yang valid.',
        ]);

        $res->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals('REJECTED', $req->fresh()->status);
        $this->assertEquals('Tidak disertakan nomor tiket incident IT yang valid.', $req->fresh()->rejection_reason);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'access_request_rejected',
            'subject_type' => 'AccessRequest',
            'subject_id' => $req->id,
        ]);
    }

    public function test_inactive_employee_triggers_automatic_access_and_credential_revocation(): void
    {
        $superadmin = Admin::create([
            'name' => 'HR Director',
            'email' => 'hr.director@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'super_admin',
        ]);

        $employee = Employee::create([
            'employee_id' => 'EMP-1004',
            'nik' => '3201010101010004',
            'name' => 'Bambang Resigned',
            'email' => 'bambang@pkp.co.id',
            'department' => 'Operations',
            'role' => 'Officer',
            'card_no' => '1122334455',
            'employment_type' => 'PERMANENT',
            'employment_status' => 'ACTIVE',
        ]);

        $req = AccessRequest::create([
            'request_number' => 'REQ-2026-0006',
            'employee_id' => $employee->id,
            'building_name' => 'Kantor Pusat PKP',
            'business_reason' => 'Akses operasional',
            'status' => 'APPROVED',
            'valid_from' => Carbon::today()->toDateString(),
        ]);

        $cred = CredentialRecord::create([
            'credential_number' => 'CRD-2026-0001',
            'employee_id' => $employee->id,
            'credential_type' => 'CARD',
            'card_number' => '1122334455',
            'masked_identifier' => '******4455',
            'status' => 'ACTIVE',
        ]);
        CredentialDeviceSync::create([
            'credential_record_id' => $cred->id,
            'employee_id' => $employee->id,
            'door_id' => $this->doorA->id,
            'operation' => 'ADD',
            'status' => 'SUCCESS',
            'idempotency_key' => 'employee-active-door-a',
        ]);

        Sanctum::actingAs($superadmin);

        // Deactivate employee
        $res = $this->deleteJson("/api/v1/user-management/employees/{$employee->id}");
        $res->assertStatus(200);

        // Verify request revoked
        $this->assertEquals('REVOKED', $req->fresh()->status);

        // Verify credential revoked
        $this->assertEquals('REVOKED', $cred->fresh()->status);

        // Verify device revocation sync queued
        $this->assertDatabaseHas('credential_device_syncs', [
            'credential_record_id' => $cred->id,
            'operation' => 'REVOKE',
            'status' => 'QUEUED',
        ]);
    }

    public function test_intern_completion_triggers_automatic_access_revocation(): void
    {
        $hrd = Admin::create([
            'name' => 'HR Coordinator',
            'email' => 'coord@pkp.co.id',
            'password' => Hash::make('password'),
            'role' => 'hrd',
        ]);

        $intern = Internship::create([
            'intern_id' => 'INT-2026-0001',
            'status' => 'ACTIVE',
            'institution' => 'Universitas Indonesia',
            'major' => 'Sistem Informasi',
            'position_title' => 'Intern DevOps',
            'start_date' => Carbon::now()->subMonths(3)->toDateString(),
            'end_date' => Carbon::today()->toDateString(),
        ]);

        $req = AccessRequest::create([
            'request_number' => 'REQ-2026-0007',
            'internship_id' => $intern->id,
            'building_name' => 'Kantor Pusat PKP',
            'business_reason' => 'Akses magang',
            'status' => 'APPROVED',
            'valid_from' => Carbon::now()->subMonths(3)->toDateString(),
        ]);

        $cred = CredentialRecord::create([
            'credential_number' => 'CRD-2026-0002',
            'internship_id' => $intern->id,
            'credential_type' => 'CARD',
            'masked_identifier' => 'CRD-INT-1',
            'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($hrd);

        $res = $this->postJson("/api/v1/internships/{$intern->id}/complete", [
            'completion_notes' => 'Magang selesai dengan memuaskan',
        ]);
        $res->assertStatus(200);

        // Verify request revoked
        $this->assertEquals('REVOKED', $req->fresh()->status);

        // Verify credential revoked
        $this->assertEquals('REVOKED', $cred->fresh()->status);
    }

    public function test_building_scope_allows_same_forbids_cross_and_super_admin_bypasses(): void
    {
        $pusat = Building::create(['code' => 'PUSAT', 'name' => 'Kantor Pusat PKP', 'is_active' => true]);
        $cabang = Building::create(['code' => 'SBY', 'name' => 'Gedung Cabang Surabaya', 'is_active' => true]);
        $this->doorA->update(['building_id' => $pusat->id]);
        $this->doorC->update(['building_id' => $cabang->id]);

        $adminEmployee = Employee::create([
            'employee_id' => 'EMP-ADMIN-PUSAT', 'nik' => '3201010101010101', 'name' => 'Admin Pusat',
            'email' => 'admin.pusat.employee@pkp.co.id', 'department' => 'Facility', 'role' => 'Admin',
            'building_id' => $pusat->id, 'employment_type' => 'PERMANENT', 'employment_status' => 'ACTIVE',
        ]);
        $sameEmployee = Employee::create([
            'employee_id' => 'EMP-SAME', 'nik' => '3201010101010102', 'name' => 'Pegawai Pusat',
            'email' => 'same@pkp.co.id', 'department' => 'IT', 'role' => 'Staff',
            'building_id' => $pusat->id, 'employment_type' => 'PERMANENT', 'employment_status' => 'ACTIVE',
        ]);
        $crossEmployee = Employee::create([
            'employee_id' => 'EMP-CROSS', 'nik' => '3201010101010103', 'name' => 'Pegawai Cabang',
            'email' => 'cross@pkp.co.id', 'department' => 'IT', 'role' => 'Staff',
            'building_id' => $cabang->id, 'employment_type' => 'PERMANENT', 'employment_status' => 'ACTIVE',
        ]);
        $buildingAdmin = Admin::create([
            'name' => 'Building Admin', 'email' => 'scope.admin@pkp.co.id', 'password' => Hash::make('password'),
            'role' => 'building_admin', 'employee_id' => $adminEmployee->id,
            'assigned_building' => 'Gedung Cabang Surabaya',
        ]);
        $superAdmin = Admin::create([
            'name' => 'Super Admin Scope', 'email' => 'scope.super@pkp.co.id',
            'password' => Hash::make('password'), 'role' => 'super_admin',
        ]);
        $sameCredential = CredentialRecord::create([
            'credential_number' => 'CRD-SAME', 'employee_id' => $sameEmployee->id,
            'credential_type' => 'CARD', 'masked_identifier' => '****SAME', 'status' => 'ACTIVE',
        ]);
        $crossCredential = CredentialRecord::create([
            'credential_number' => 'CRD-CROSS', 'employee_id' => $crossEmployee->id,
            'credential_type' => 'CARD', 'masked_identifier' => '****CROSS', 'status' => 'ACTIVE',
        ]);

        Sanctum::actingAs($buildingAdmin);
        $this->getJson("/api/v1/access/credentials/{$sameCredential->id}")->assertOk();
        $this->getJson("/api/v1/access/credentials/{$crossCredential->id}")->assertForbidden();
        $this->postJson('/api/v1/access/profiles', [
            'code' => 'EMPTY_SCOPE', 'name' => 'Empty Scope', 'allowed_doors' => [],
        ])->assertUnprocessable();
        $this->postJson('/api/v1/access/profiles', [
            'code' => 'CROSS_SCOPE', 'name' => 'Cross Scope', 'allowed_doors' => ['DOOR-C'],
        ])->assertForbidden();
        $this->postJson('/api/v1/access/profiles', [
            'code' => 'SAME_SCOPE', 'name' => 'Same Scope', 'allowed_doors' => ['DOOR-A'],
        ])->assertCreated();

        Sanctum::actingAs($superAdmin);
        $this->getJson("/api/v1/access/credentials/{$crossCredential->id}")->assertOk();
        $this->postJson('/api/v1/access/profiles', [
            'code' => 'SUPER_CROSS_SCOPE', 'name' => 'Super Cross Scope',
            'building_name' => 'Gedung Cabang Surabaya', 'allowed_doors' => ['DOOR-C'],
        ])->assertCreated();
    }

    public function test_device_retry_is_scoped_and_only_requeues_without_fake_success(): void
    {
        $pusat = Building::create(['code' => 'PUSAT-Q', 'name' => 'Pusat Queue', 'is_active' => true]);
        $cabang = Building::create(['code' => 'CABANG-Q', 'name' => 'Cabang Queue', 'is_active' => true]);
        $this->doorA->update(['building_id' => $pusat->id]);
        $this->doorC->update(['building_id' => $cabang->id]);
        $adminEmployee = Employee::create([
            'employee_id' => 'EMP-QUEUE-ADMIN', 'nik' => '3201010101010111', 'name' => 'Queue Admin',
            'email' => 'queue.admin.employee@pkp.co.id', 'department' => 'Facility', 'role' => 'Admin',
            'building_id' => $pusat->id, 'employment_type' => 'PERMANENT', 'employment_status' => 'ACTIVE',
        ]);
        $admin = Admin::create([
            'name' => 'Queue Admin', 'email' => 'queue.admin@pkp.co.id', 'password' => Hash::make('password'),
            'role' => 'building_admin', 'employee_id' => $adminEmployee->id,
        ]);
        $credential = CredentialRecord::create([
            'credential_number' => 'CRD-QUEUE', 'credential_type' => 'CARD',
            'masked_identifier' => '****QUEUE', 'status' => 'ACTIVE',
        ]);
        $sameSync = CredentialDeviceSync::create([
            'credential_record_id' => $credential->id, 'door_id' => $this->doorA->id,
            'operation' => 'ADD', 'status' => 'FAILED', 'attempt_count' => 2,
            'error_summary' => 'offline', 'completed_at' => now(), 'idempotency_key' => 'same-retry',
        ]);
        $crossSync = CredentialDeviceSync::create([
            'credential_record_id' => $credential->id, 'door_id' => $this->doorC->id,
            'operation' => 'ADD', 'status' => 'FAILED', 'attempt_count' => 1,
            'error_summary' => 'offline', 'idempotency_key' => 'cross-retry',
        ]);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/access/device-syncs/{$crossSync->id}/retry")->assertForbidden();
        $this->postJson("/api/v1/access/device-syncs/{$sameSync->id}/retry")
            ->assertOk()->assertJsonPath('data.status', 'QUEUED');
        $sameSync->refresh();
        $this->assertSame('QUEUED', $sameSync->status);
        $this->assertSame(3, $sameSync->attempt_count);
        $this->assertNull($sameSync->completed_at);
        $this->assertNull($sameSync->error_summary);
    }
}
