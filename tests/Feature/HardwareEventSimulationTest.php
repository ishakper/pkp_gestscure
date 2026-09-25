<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AccessLog;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareEventSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected Door $door;
    protected Employee $employee;
    protected Admin $admin;
    protected string $adminEmail;

    protected function setUp(): void
    {
        parent::setUp();

        $this->door = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door A - Gedung Utama',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        $this->employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'card_no' => 'CARD-1001',
            'department' => 'IT Support',
        ]);

        $this->adminEmail = 'test_'.uniqid().'@accesscontrol.local';
        $this->admin = Admin::create([
            'name' => 'Super Administrator',
            'email' => $this->adminEmail,
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
    }

    /**
     * Test Scenario 1: DOOR_FORCED_OPEN (Break-in attempt / High severity alarm)
     */
    public function test_simulation_door_forced_open_creates_alarm_log(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => $this->door->door_id,
                'event_type' => 'DOOR_FORCED_OPEN',
                'verify_method' => 'Sensor',
                'access_status' => 'Alarm',
            ], [
                'X-Device-Secret' => 'secret_simulator_key_2026',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'event_type' => 'DOOR_FORCED_OPEN',
                    'door_id' => $this->door->door_id,
                    'access_status' => 'Alarm',
                ],
            ]);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'event_type' => 'DOOR_FORCED_OPEN',
            'access_status' => 'Alarm',
            'verify_method' => 'Sensor',
        ]);
    }

    /**
     * Test Scenario 2: TAMPER_ALARM (Hardware physical enclosure tamper trigger)
     */
    public function test_simulation_tamper_alarm_creates_tamper_log(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => $this->door->door_id,
                'event_type' => 'TAMPER_ALARM',
                'verify_method' => 'Sensor',
                'access_status' => 'Alarm',
            ], [
                'X-Device-Secret' => 'secret_simulator_key_2026',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'event_type' => 'TAMPER_ALARM',
                    'door_id' => $this->door->door_id,
                    'access_status' => 'Alarm',
                ],
            ]);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'event_type' => 'TAMPER_ALARM',
            'access_status' => 'Alarm',
        ]);
    }

    /**
     * Test Scenario 3: DURESS_FINGERPRINT (Employee forced under duress / silent alert)
     */
    public function test_simulation_duress_fingerprint_creates_duress_log(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => $this->door->door_id,
                'user' => 'NIK-882101',
                'event_type' => 'DURESS_FINGERPRINT',
                'verify_method' => 'Duress_Fingerprint',
                'access_status' => 'Duress',
            ], [
                'X-Device-Secret' => 'secret_simulator_key_2026',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'event_type' => 'DURESS_FINGERPRINT',
                    'door_id' => $this->door->door_id,
                    'employee_name' => 'Budi Santoso',
                    'access_status' => 'Duress',
                ],
            ]);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'DURESS_FINGERPRINT',
            'access_status' => 'Duress',
            'verify_method' => 'Duress_Fingerprint',
        ]);
    }

    /**
     * Test Admin API filtering by Alarm & Duress status
     */
    public function test_admin_can_filter_access_logs_by_alarm_and_duress(): void
    {
        // 1. Create 3 distinct logs
        AccessLog::create([
            'log_id' => 'LOG-TEST-001',
            'door_id' => $this->door->id,
            'employee_id' => $this->employee->id,
            'nik' => $this->employee->nik,
            'event_type' => 'STANDARD_TAP',
            'device_ip' => '192.168.90.11',
            'verify_method' => 'Fingerprint',
            'access_status' => 'Granted',
            'timestamp' => now()->subMinutes(10),
        ]);

        AccessLog::create([
            'log_id' => 'LOG-TEST-002',
            'door_id' => $this->door->id,
            'employee_id' => null,
            'nik' => 'SENSOR-FORCED-OPEN',
            'event_type' => 'DOOR_FORCED_OPEN',
            'device_ip' => '192.168.90.11',
            'verify_method' => 'Sensor',
            'access_status' => 'Alarm',
            'reason' => 'Door forced open',
            'timestamp' => now()->subMinutes(5),
        ]);

        AccessLog::create([
            'log_id' => 'LOG-TEST-003',
            'door_id' => $this->door->id,
            'employee_id' => $this->employee->id,
            'nik' => $this->employee->nik,
            'event_type' => 'DURESS_FINGERPRINT',
            'device_ip' => '192.168.90.11',
            'verify_method' => 'Duress_Fingerprint',
            'access_status' => 'Duress',
            'reason' => 'Duress triggered',
            'timestamp' => now()->subMinutes(2),
        ]);

        // 2. Query as Authenticated Admin
        $alarmResponse = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/access-logs?status=Alarm&limit=10');

        $alarmResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'total_records' => 1,
            ])
            ->assertJsonPath('data.0.log_id', 'LOG-TEST-002')
            ->assertJsonPath('data.0.event_type', 'DOOR_FORCED_OPEN')
            ->assertJsonPath('data.0.access_status', 'Alarm');

        $duressResponse = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/access-logs?status=Duress&limit=10');

        $duressResponse->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'total_records' => 1,
            ])
            ->assertJsonPath('data.0.log_id', 'LOG-TEST-003')
            ->assertJsonPath('data.0.event_type', 'DURESS_FINGERPRINT')
            ->assertJsonPath('data.0.access_status', 'Duress');
    }
}
