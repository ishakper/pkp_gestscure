<?php

namespace Tests\Feature;

use App\Jobs\SyncDoorAccessJob;
use App\Models\Admin;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BiometricUserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $superAdmin;
    protected Admin $buildingAdmin;
    protected Employee $employee;
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

        $this->buildingAdmin = Admin::create([
            'name' => 'Building Admin Gedung B',
            'email' => 'admin.gedungb@pkp.co.id',
            'password' => bcrypt('password'),
            'role' => 'building_admin',
            'assigned_building' => 'Gedung B',
        ]);

        $this->employee = Employee::create([
            'employee_id' => 'EMP-100234',
            'nik' => 'NIK-3201992837',
            'name' => 'Ahmad Fauzi',
            'card_no' => 'CARD-778899',
            'department' => 'Engineering',
            'employment_status' => 'ACTIVE',
        ]);

        $this->doorA = Door::create([
            'door_id' => 'DOOR-A',
            'door_name' => 'Pintu Utama (DOOR-A)',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        $this->doorB = Door::create([
            'door_id' => 'DOOR-B',
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
}
