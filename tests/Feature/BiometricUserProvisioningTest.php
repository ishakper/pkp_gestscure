<?php

namespace Tests\Feature;

use App\Jobs\SyncDoorAccessJob;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiometricUserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $superAdmin;
    protected Admin $buildingAdmin;
    protected Admin $hrdAdmin;
    protected Admin $developerAdmin;
    protected Admin $devopsAdmin;
    protected Admin $employeeAdmin;
    protected Admin $internAdmin;
    protected Employee $employee;
    protected Employee $otherEmployee;
    protected Door $doorA;
    protected Door $doorB;
    protected HikvisionIsapiService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(HikvisionIsapiService::class);

        $this->superAdmin = Admin::create([
            'name' => 'HR Super Admin',
            'email' => 'hr.admin@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->hrdAdmin = Admin::create([
            'name' => 'HRD Manager',
            'email' => 'hrd@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'hrd',
        ]);

        $this->buildingAdmin = Admin::create([
            'name' => 'Building Admin Gedung B',
            'email' => 'admin.gedungb@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung B',
        ]);

        $this->developerAdmin = Admin::create([
            'name' => 'Tech Lead Developer',
            'email' => 'developer@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'developer',
        ]);

        $this->devopsAdmin = Admin::create([
            'name' => 'DevOps Engineer',
            'email' => 'devops@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'devops',
        ]);

        $this->employee = Employee::create([
            'employee_id' => 'EMP-100234',
            'nik' => 'NIK-3201992837',
            'name' => 'Ahmad Fauzi',
            'card_no' => 'CARD-778899',
            'department' => 'Engineering',
            'employment_status' => 'ACTIVE',
        ]);

        $this->otherEmployee = Employee::create([
            'employee_id' => 'EMP-100235',
            'nik' => 'NIK-3201992838',
            'name' => 'Siti Nurhaliza',
            'card_no' => 'CARD-778800',
            'department' => 'Finance',
            'employment_status' => 'ACTIVE',
        ]);

        $this->employeeAdmin = Admin::create([
            'name' => 'Ahmad Fauzi User',
            'email' => 'ahmad.fauzi@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'employee',
            'employee_id' => $this->employee->id,
        ]);

        $this->internAdmin = Admin::create([
            'name' => 'Intern User',
            'email' => 'intern@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'intern',
        ]);

        $this->doorA = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Pintu Utama (DOOR-A)',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        $this->doorB = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Ruang Server (DOOR-B)',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.12',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);
    }

    /** @test */
    public function test_build_user_info_payload_matches_hikvision_isapi_specification(): void
    {
        $payload = $this->service->buildUserInfoPayload($this->employee, $this->doorB, [
            'userType' => 'normal',
            'closeDelay' => 7,
            'userVerifyMode' => 'cardOrFaceOrFp',
        ]);

        $this->assertArrayHasKey('UserInfo', $payload);
        $userInfo = $payload['UserInfo'];

        $this->assertEquals('EMP-100234', $userInfo['employeeNo']);
        $this->assertEquals('Ahmad Fauzi', $userInfo['name']);
        $this->assertEquals('normal', $userInfo['userType']);
        $this->assertEquals(7, $userInfo['closeDelay']);
        $this->assertEquals('cardOrFaceOrFp', $userInfo['userVerifyMode']);
        $this->assertTrue($userInfo['Valid']['enable']);
        $this->assertEquals('local', $userInfo['Valid']['timeType']);
        $this->assertEquals(1, $userInfo['RightPlan'][0]['doorNo']);
        $this->assertEquals('1', $userInfo['RightPlan'][0]['planTemplateNo']);
    }

    /** @test */
    public function test_set_user_info_in_mock_mode(): void
    {
        Config::set('services.hikvision.use_mock', true);

        $res = $this->service->setUserInfo($this->doorB, $this->employee);

        $this->assertTrue($res['status']);
        $this->assertEquals(1, $res['statusCode']);
        $this->assertNull($res['error']);
    }

    /** @test */
    public function test_set_user_info_in_real_http_mode_success(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/UserInfo/SetUp*' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
                'subStatusCode' => 'ok',
            ], 200),
        ]);

        $res = $this->service->setUserInfo($this->doorB, $this->employee);

        $this->assertTrue($res['status']);
        $this->assertEquals(1, $res['statusCode']);
        $this->assertNull($res['error']);
    }

    /** @test */
    public function test_set_user_info_in_real_http_mode_handles_device_error(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/UserInfo/SetUp*' => Http::response([
                'statusCode' => 4,
                'statusString' => 'Device Error',
                'errorMsg' => 'Terminal storage full',
            ], 400),
        ]);

        $res = $this->service->setUserInfo($this->doorB, $this->employee);

        $this->assertFalse($res['status']);
        $this->assertEquals(400, $res['statusCode']);
        $this->assertStringContainsString('Terminal storage full', $res['error']);
    }

    /** @test */
    public function test_provision_employee_access_full_workflow(): void
    {
        Config::set('services.hikvision.use_mock', true);

        $res = $this->service->provisionEmployeeAccess($this->doorB, $this->employee);

        $this->assertTrue($res['status']);
        $this->assertEquals(200, $res['statusCode']);
        $this->assertTrue($res['steps']['device_ping']);
        $this->assertTrue($res['steps']['user_info']['status']);
        $this->assertTrue($res['steps']['card_sync']['status']);
        $this->assertTrue($res['steps']['access_right']['status']);
    }

    /** @test */
    public function test_admin_can_sync_employee_biometric_to_selected_doors_synchronously(): void
    {
        Config::set('services.hikvision.use_mock', true);
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
            'mode' => 'sync',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.employee.employee_id', 'EMP-100234')
            ->assertJsonPath('data.synced_doors.0.door_id', 'DOOR-B')
            ->assertJsonPath('data.synced_doors.0.status', 'synced');

        $this->assertDatabaseHas('door_assignments', [
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorB->id,
            'sync_status' => 'synced',
            'sync_type' => 'FULL',
        ]);

        $assignment = DoorAssignment::where('employee_id', $this->employee->id)
            ->where('door_id', $this->doorB->id)
            ->first();

        $this->assertNotNull($assignment->last_synced_at);
        $this->assertNotNull($assignment->user_info_synced_at);
        $this->assertNotNull($assignment->card_synced_at);
    }

    /** @test */
    public function test_admin_can_sync_employee_biometric_asynchronously_via_queue(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-A', 'DOOR-B'],
            'mode' => 'async',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.synced_doors.0.status', 'queued')
            ->assertJsonPath('data.synced_doors.1.status', 'queued');

        Queue::assertPushed(SyncDoorAccessJob::class, 2);
    }

    /** @test */
    public function test_sync_single_door_employee_endpoint(): void
    {
        Config::set('services.hikvision.use_mock', true);
        Sanctum::actingAs($this->superAdmin);

        $response = $this->postJson("/api/v1/admin/doors/DOOR-B/sync-employee/{$this->employee->id}");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.door_id', 'DOOR-B')
            ->assertJsonPath('data.sync_status', 'synced');

        $this->assertDatabaseHas('door_assignments', [
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorB->id,
            'sync_status' => 'synced',
        ]);
    }

    /** @test */
    public function test_sync_employee_biometric_handles_offline_door_gracefully(): void
    {
        Config::set('services.hikvision.use_mock', true);
        Sanctum::actingAs($this->superAdmin);

        $offlineDoor = Door::create([
            'door_id' => 'DOOR-OFFLINE',
            'door_name' => 'Pintu Gudang Rusak',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.99', // Simulates offline in mock mode
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'offline',
        ]);

        $response = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-OFFLINE'],
            'mode' => 'sync',
        ]);

        $response->assertStatus(502)
            ->assertJsonPath('status', 'error')
            ->assertJsonPath('data.failed_doors.0.door_id', 'DOOR-OFFLINE')
            ->assertJsonPath('data.failed_doors.0.status', 'failed');

        $this->assertDatabaseHas('door_assignments', [
            'employee_id' => $this->employee->id,
            'door_id' => $offlineDoor->id,
            'sync_status' => 'failed',
        ]);
    }

    /** @test */
    public function test_building_admin_cannot_sync_doors_in_other_building(): void
    {
        Sanctum::actingAs($this->buildingAdmin); // Assigned to Gedung B only

        // Trying to sync DOOR-A which is in Gedung A
        $response = $this->postJson("/api/v1/admin/doors/DOOR-A/sync-employee/{$this->employee->id}");

        $response->assertStatus(403)
            ->assertJsonPath('status', 'error');

        // Trying batch sync specifying DOOR-A
        $batchResponse = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-A'],
        ]);
        $batchResponse->assertStatus(403);
    }

    /** @test */
    public function test_building_admin_cannot_provision_or_view_cross_building_employee_by_direct_id(): void
    {
        $this->employee->update(['building_id' => null]);
        $this->employee->doors()->sync([$this->doorA->id]);
        Sanctum::actingAs($this->buildingAdmin);

        Http::preventStrayRequests();

        $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/doors/DOOR-B/sync-employee/{$this->employee->id}")
            ->assertForbidden();

        $this->getJson("/api/v1/user-management/employees/{$this->employee->id}/door-sync-status")
            ->assertForbidden();

        $this->assertDatabaseMissing('door_assignments', [
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorB->id,
        ]);
    }

    /** @test */
    public function test_door_sync_status_endpoint_returns_biometric_timestamps(): void
    {
        Sanctum::actingAs($this->superAdmin);

        DoorAssignment::create([
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorB->id,
            'sync_status' => 'synced',
            'sync_attempts' => 1,
            'last_synced_at' => now()->subMinutes(10),
            'user_info_synced_at' => now()->subMinutes(10),
            'card_synced_at' => now()->subMinutes(10),
            'sync_type' => 'FULL',
        ]);

        $response = $this->getJson("/api/v1/user-management/employees/{$this->employee->id}/door-sync-status");

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.assignments.0.door_id', 'DOOR-B')
            ->assertJsonPath('data.assignments.0.sync_status', 'synced');

        $this->assertNotNull($response->json('data.assignments.0.user_info_synced_at'));
        $this->assertNotNull($response->json('data.assignments.0.card_synced_at'));
    }

    /** @test */
    public function test_sync_door_access_job_executes_biometric_provisioning(): void
    {
        Config::set('services.hikvision.use_mock', true);

        $assignment = DoorAssignment::create([
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorB->id,
            'sync_status' => 'pending',
            'sync_attempts' => 0,
        ]);

        $job = new SyncDoorAccessJob($assignment->id);
        $job->handle($this->service);

        $assignment->refresh();

        $this->assertEquals('synced', $assignment->sync_status);
        $this->assertNotNull($assignment->last_synced_at);
        $this->assertNotNull($assignment->user_info_synced_at);
        $this->assertNotNull($assignment->card_synced_at);
        $this->assertEquals('FULL', $assignment->sync_type);
    }

    // =========================================================================
    // SECTION: IDEMPOTENCY VERIFICATION
    // =========================================================================

    /** @test */
    public function test_provisioning_idempotency_repeat_sync_does_not_duplicate_assignments(): void
    {
        Config::set('services.hikvision.use_mock', true);
        Sanctum::actingAs($this->superAdmin);

        // First provisioning call
        $first = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
            'mode' => 'sync',
        ]);
        $first->assertStatus(200);

        $firstAssignment = DoorAssignment::where('employee_id', $this->employee->id)
            ->where('door_id', $this->doorB->id)
            ->first();

        $this->assertNotNull($firstAssignment);
        $initialSyncedAt = $firstAssignment->last_synced_at;

        // Wait 1 second and call again
        sleep(1);
        $second = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
            'mode' => 'sync',
        ]);
        $second->assertStatus(200);

        // Exactly one record must exist
        $count = DoorAssignment::where('employee_id', $this->employee->id)
            ->where('door_id', $this->doorB->id)
            ->count();
        $this->assertEquals(1, $count);

        $secondAssignment = DoorAssignment::where('employee_id', $this->employee->id)
            ->where('door_id', $this->doorB->id)
            ->first();

        $this->assertEquals('synced', $secondAssignment->sync_status);
        $this->assertTrue($secondAssignment->last_synced_at->gte($initialSyncedAt));
    }

    /** @test */
    public function test_retry_after_transient_failure_succeeds_idempotently(): void
    {
        Config::set('services.hikvision.use_mock', true);

        // Create assignment initially in failed state
        $assignment = DoorAssignment::create([
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorB->id,
            'sync_status' => 'failed',
            'sync_attempts' => 1,
            'last_sync_error' => 'Simulated prior failure',
        ]);

        $job = new SyncDoorAccessJob($assignment->id);
        $job->handle($this->service);

        $assignment->refresh();

        $this->assertEquals('synced', $assignment->sync_status);
        $this->assertNull($assignment->last_sync_error);
        $this->assertEquals(2, $assignment->sync_attempts);
        $this->assertNotNull($assignment->last_synced_at);

        // Uniqueness preserved
        $totalCount = DoorAssignment::where('employee_id', $this->employee->id)
            ->where('door_id', $this->doorB->id)
            ->count();
        $this->assertEquals(1, $totalCount);
    }

    // =========================================================================
    // SECTION: AUTHORIZATION NEGATIVE MATRIX & RBAC GATES
    // =========================================================================

    /** @test */
    public function test_unauthenticated_request_is_denied(): void
    {
        $response = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
        ]);

        $response->assertStatus(401);
    }

    /** @test */
    public function test_employee_and_intern_cannot_self_provision_biometrics(): void
    {
        // Employee attempting self-provisioning
        Sanctum::actingAs($this->employeeAdmin);

        $empRes = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
        ]);
        $empRes->assertStatus(403);
        $this->assertStringContainsString('cannot self-provision', $empRes->json('message'));

        // Intern attempting self-provisioning
        Sanctum::actingAs($this->internAdmin);

        $internRes = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
        ]);
        $internRes->assertStatus(403);
    }

    /** @test */
    public function test_developer_and_devops_are_denied_biometric_provisioning(): void
    {
        // Developer denied
        Sanctum::actingAs($this->developerAdmin);

        $devRes = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
        ]);
        $devRes->assertStatus(403);
        $this->assertStringContainsString('Technical roles', $devRes->json('message'));

        // DevOps denied
        Sanctum::actingAs($this->devopsAdmin);

        $devopsRes = $this->postJson("/api/v1/admin/doors/DOOR-B/sync-employee/{$this->employee->id}");
        $devopsRes->assertStatus(403);
        $this->assertStringContainsString('Technical roles', $devopsRes->json('message'));
    }

    /** @test */
    public function test_hrd_manager_is_allowed_to_provision_biometrics(): void
    {
        Config::set('services.hikvision.use_mock', true);
        Sanctum::actingAs($this->hrdAdmin);

        $response = $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'success');
    }

    /** @test */
    public function test_cross_employee_status_lookup_idor_is_prevented(): void
    {
        // Employee attempting to view OTHER employee's sync status
        Sanctum::actingAs($this->employeeAdmin);

        $response = $this->getJson("/api/v1/user-management/employees/{$this->otherEmployee->id}/door-sync-status");
        $response->assertStatus(403);
    }

    // =========================================================================
    // SECTION: PRIVACY, AUDIT, & QUEUE SERIALIZATION AUDIT
    // =========================================================================

    /** @test */
    public function test_last_payload_column_is_not_present_in_schema(): void
    {
        // Verifies zero storage of raw payloads, biometric templates, or credentials
        $this->assertFalse(Schema::hasColumn('door_assignments', 'last_payload'));
    }

    /** @test */
    public function test_activity_log_does_not_expose_raw_card_numbers_or_credentials(): void
    {
        Config::set('services.hikvision.use_mock', true);
        Sanctum::actingAs($this->superAdmin);

        $this->postJson("/api/v1/user-management/employees/{$this->employee->id}/sync-biometric", [
            'door_ids' => ['DOOR-B'],
        ]);

        $log = ActivityLog::where('action', 'biometric_user_provisioning')
            ->where('subject_id', $this->employee->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertStringNotContainsString('CARD-778899', $log->description);
        $this->assertStringNotContainsString('password', $log->description);
        $this->assertStringNotContainsString('UserInfo', $log->description);
    }

    /** @test */
    public function test_queue_job_serializes_only_identifiers_without_credentials_or_payloads(): void
    {
        $assignment = DoorAssignment::create([
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorB->id,
            'sync_status' => 'pending',
        ]);

        $job = new SyncDoorAccessJob($assignment->id);
        $serialized = serialize($job);

        // Job must NOT contain card numbers, passwords, or raw payloads
        $this->assertStringNotContainsString('CARD-778899', $serialized);
        $this->assertStringNotContainsString('password', $serialized);
        $this->assertStringNotContainsString('UserInfo', $serialized);
        $this->assertStringNotContainsString('Hikvision@', $serialized);
    }
}
