<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected Door $doorA;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'admin@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        $this->doorA = Door::create([
            'door_id' => 'DOOR-A',
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
                'inserted_count' => 1,
            ]);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->doorA->id,
            'employee_id' => $this->employee->id,
            'verify_method' => 'Card',
            'access_status' => 'Granted',
        ]);
    }

    /**
     * Test authenticated admin dashboard view renders properly
     */
    public function test_authenticated_admin_can_view_dashboard_page(): void
    {
        $response = $this->actingAs($this->admin)->get('/');

        $response->assertStatus(200)
            ->assertSee('SecureGate')
            ->assertSee('Cek Semua Koneksi Terminal')
            ->assertSee('Sinkronkan Log Pintu')
            ->assertSee('Cabut Semua Akses');
    }
}
