<?php

namespace Tests\Feature;

use App\Jobs\SyncDoorAccessJob;
use App\Models\AccessLog;
use App\Models\Admin;
use App\Models\BiometricStatus;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiListingAndAuditTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;
    protected Door $doorA;
    protected Door $doorB;
    protected Door $doorC;
    protected Door $doorD;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Super Administrator',
            'email' => 'admin@accesscontrol.local',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);

        Sanctum::actingAs($this->admin);

        $this->doorA = Door::create([
            'door_id' => 'DOOR-A',
            'name' => 'Door A - Kantor Utama',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'connection_status' => 'online',
        ]);

        $this->doorB = Door::create([
            'door_id' => 'DOOR-B',
            'name' => 'Door B - Server Room',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
            'connection_status' => 'online',
        ]);

        $this->doorC = Door::create([
            'door_id' => 'DOOR-C',
            'name' => 'Door C - Lapangan',
            'location' => 'Gedung C',
            'device_ip' => '192.168.90.13',
            'connection_status' => 'offline',
        ]);

        $this->doorD = Door::create([
            'door_id' => 'DOOR-D',
            'name' => 'Door D - Pabrik',
            'location' => 'Gedung D',
            'device_ip' => '192.168.90.14',
            'connection_status' => 'online',
        ]);
    }

    public function test_doors_lookup_returns_lightweight_list(): void
    {
        $response = $this->getJson('/api/v1/user-management/doors-lookup');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
            ])
            ->assertJsonStructure([
                'status',
                'data' => [
                    '*' => ['id', 'door_id', 'name', 'location']
                ]
            ]);

        $this->assertCount(4, $response->json('data'));
    }

    public function test_users_listing_contains_card_no_biometric_and_door_assign(): void
    {
        $emp = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'card_no' => 'CARD-882101',
            'department' => 'IT Support',
            'role_jabatan' => 'Lead Infrastructure',
        ]);

        BiometricStatus::create([
            'employee_id' => $emp->id,
            'has_fingerprint' => true,
            'fingerprint_enrolled' => true,
            'card_enrolled' => true,
        ]);

        DoorAssignment::create([
            'employee_id' => $emp->id,
            'door_id' => $this->doorA->id,
            'sync_status' => 'synced',
        ]);

        DoorAssignment::create([
            'employee_id' => $emp->id,
            'door_id' => $this->doorB->id,
            'sync_status' => 'failed',
            'last_sync_error' => 'Connection timeout',
        ]);

        $response = $this->getJson('/api/v1/user-management/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'pagination',
                'data' => [
                    '*' => [
                        'user_id',
                        'nik',
                        'name',
                        'card_no',
                        'department',
                        'role',
                        'biometric_status' => ['fingerprint_enrolled', 'card_enrolled'],
                        'door_assign' => [
                            '*' => ['door_id', 'door_name', 'sync_status']
                        ],
                        'created_at',
                    ]
                ]
            ]);

        $firstUser = $response->json('data.0');
        $this->assertEquals('CARD-882101', $firstUser['card_no']);
        $this->assertTrue($firstUser['biometric_status']['fingerprint_enrolled']);
        $this->assertTrue($firstUser['biometric_status']['card_enrolled']);
        $this->assertCount(2, $firstUser['door_assign']);

        $statuses = collect($firstUser['door_assign'])->pluck('sync_status')->all();
        $this->assertContains('synced', $statuses);
        $this->assertContains('failed', $statuses);
    }

    public function test_admin_doors_returns_all_doors_with_assigned_users_count(): void
    {
        $emp = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'department' => 'IT',
        ]);

        DoorAssignment::create([
            'employee_id' => $emp->id,
            'door_id' => $this->doorA->id,
            'sync_status' => 'synced',
        ]);

        $response = $this->getJson('/api/v1/admin/doors');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'total_doors' => 4,
            ])
            ->assertJsonStructure([
                'status',
                'total_doors',
                'data' => [
                    '*' => [
                        'door_id',
                        'door_name',
                        'location',
                        'device_ip',
                        'device_model',
                        'connection_status',
                        'total_assigned_users',
                    ]
                ]
            ]);

        $doorAData = collect($response->json('data'))->firstWhere('door_id', 'DOOR-A');
        $this->assertEquals(1, $doorAData['total_assigned_users']);
        $this->assertEquals('online', $doorAData['connection_status']);

        $doorCData = collect($response->json('data'))->firstWhere('door_id', 'DOOR-C');
        $this->assertEquals(0, $doorCData['total_assigned_users']);
        $this->assertEquals('offline', $doorCData['connection_status']);
    }

    public function test_admin_access_logs_filtering_by_status(): void
    {
        AccessLog::create([
            'log_id' => 'LOG-001',
            'door_id' => $this->doorA->id,
            'auth_method' => 'Fingerprint',
            'status' => 'Granted',
            'timestamp' => now()->subMinutes(10),
        ]);

        AccessLog::create([
            'log_id' => 'LOG-002',
            'door_id' => $this->doorA->id,
            'auth_method' => 'Card',
            'status' => 'Denied',
            'reason' => 'Unauthorized card',
            'timestamp' => now()->subMinutes(5),
        ]);

        $response = $this->getJson('/api/v1/admin/access-logs?status=Denied');

        $response->assertStatus(200);
        $logs = $response->json('data');
        $this->assertCount(1, $logs);
        $this->assertEquals('Denied', $logs[0]['access_status']);
        $this->assertEquals('Unauthorized card', $logs[0]['reason']);
    }

    public function test_assign_doors_endpoint_dispatches_sync_job(): void
    {
        Queue::fake();

        $emp = Employee::create([
            'employee_id' => 'USR-1002',
            'nik' => 'NIK-882102',
            'name' => 'Siti Rahma',
            'department' => 'HR',
        ]);

        $response = $this->postJson('/api/v1/user-management/assign-doors', [
            'employee_id' => $emp->id,
            'door_id' => 'DOOR-C',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'door_id' => 'DOOR-C',
                    'sync_status' => 'pending',
                ],
            ]);

        Queue::assertPushed(SyncDoorAccessJob::class);
    }
}
