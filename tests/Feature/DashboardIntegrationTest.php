<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected Door $doorA;
    protected Employee $employee;
    protected string $adminEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminEmail = 'test_'.uniqid().'@accesscontrol.local';
        $this->admin = Admin::create([
            'name' => 'Super Administrator',
            'email' => $this->adminEmail,
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->doorA = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door A - Gedung Utama',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'offline',
        ]);

        $this->employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'card_no' => 'CARD-1001',
            'department' => 'IT Support',
        ]);
    }

    /**
     * Test single door connection check via ISAPI
     */
    public function test_admin_can_check_single_door_connection(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        Http::fake([
            '*/System/status' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
                'DeviceStatus' => [
                    'status' => 'OK',
                    'online' => true,
                    'doorStatus' => 'closed',
                ],
                'status' => 'OK',
                'online' => true,
                'doorStatus' => 'closed',
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/admin/doors/{$this->doorA->door_id}/check-connection");

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'is_online' => true,
            ]);

        $this->assertDatabaseHas('doors', [
            'id' => $this->doorA->id,
            'connection_status' => 'online',
        ]);
    }

    public function test_terminal_auth_failure_is_classified_without_claiming_offline_only(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*System/deviceInfo*' => Http::response('<ResponseStatus><statusString>Unauthorized</statusString></ResponseStatus>', 401),
        ]);

        $this->postJson("/api/v1/admin/doors/{$this->doorA->door_id}/check-connection")
            ->assertStatus(200)
            ->assertJsonPath('is_online', false)
            ->assertJsonPath('health_status', 'auth_error')
            ->assertJsonPath('data.health_status', 'auth_error');

        $this->assertDatabaseHas('doors', ['id' => $this->doorA->id, 'health_status' => 'auth_error']);
    }

    /**
     * Test audit all doors connections
     */
    public function test_admin_can_check_all_doors_connections(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        Http::fake([
            '*/System/status' => Http::response(['statusCode' => 1, 'status' => 'OK', 'online' => true], 200),
        ]);

        $response = $this->postJson('/api/v1/admin/doors/check-all');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'total_audited' => 1,
                'online_count' => 1,
            ]);
    }

    /**
     * Test revoking door access for employee
     */
    public function test_admin_can_revoke_door_access(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        DoorAssignment::create([
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorA->id,
            'sync_status' => 'synced',
        ]);

        $this->assertDatabaseHas('door_assignments', [
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorA->id,
        ]);

        $response = $this->postJson('/api/v1/user-management/revoke-doors', [
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorA->door_id,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'revoked_count' => 1,
            ]);

        $this->assertDatabaseMissing('door_assignments', [
            'employee_id' => $this->employee->id,
            'door_id' => $this->doorA->id,
        ]);
    }

    /**
     * Test sync hardware logs from ISAPI AcsEvent
     */
    public function test_admin_can_sync_hardware_access_logs(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        Config::set('services.doors.DOOR-A.username', 'configured-user');
        Config::set('services.doors.DOOR-A.password', 'configured-password');

        Http::fake([
            '*/AccessControl/AcsEvent' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
                'AcsEvent' => [
                    'searchID' => '1',
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 1,
                    'totalMatches' => 1,
                    'InfoList' => [
                        [
                            'major' => 5,
                            'minor' => 1,
                            'time' => '2026-09-07T10:00:00+07:00',
                            'cardNo' => 'CARD-1001',
                            'employeeNoString' => 'USR-1001',
                            'name' => 'Budi Santoso',
                            'verifyMethod' => 'Card',
                            'doorNo' => 1,
                            'doorName' => 'Door A - Gedung Utama',
                            'accessStatus' => 'Granted',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/admin/access-logs/sync-hardware', [
            'door_id' => $this->doorA->door_id,
            'limit' => 10,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ]);
        $this->assertGreaterThanOrEqual(1, $response->json('inserted_count'));

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->doorA->id,
            'employee_id' => $this->employee->id,
            'verify_method' => 'Card',
            'access_status' => 'Granted',
        ]);
    }

    public function test_sync_skips_unconfigured_door_without_device_request(): void
    {
        Sanctum::actingAs($this->admin, ['*']);
        Config::set('services.doors.DOOR-A.username', null);
        Config::set('services.doors.DOOR-A.password', null);
        Config::set('services.hikvision.username', null);
        Config::set('services.hikvision.password', null);
        Http::fake();
        Http::preventStrayRequests();

        $this->postJson('/api/v1/admin/access-logs/sync-hardware', [
            'door_id' => $this->doorA->door_id,
            'limit' => 10,
        ])->assertOk()->assertJson([
            'status' => 'success',
            'total_fetched' => 0,
            'inserted_count' => 0,
        ]);

        Http::assertNothingSent();
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'sync_hardware_access_logs',
        ]);
        $this->assertStringContainsString('1 unconfigured devices skipped', (string) \App\Models\ActivityLog::latest('id')->value('description'));
    }

    /**
     * Test authenticated admin dashboard view renders properly
     */
    public function test_authenticated_admin_can_view_dashboard_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/');

        $response->assertStatus(200)
            ->assertSee('PKP Secure')
            ->assertSee('Cek Semua Koneksi Terminal')
            ->assertSee('Sinkronkan Log Pintu')
            ->assertSee('Cabut Semua Akses');
    }
}
