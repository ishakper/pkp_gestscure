<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IsapiWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected Door $door;
    protected Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->door = Door::create([
            'door_id' => 'DOOR-A',
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
    }

    /**
     * Case A (Valid Tap): Send simulated payload from IP 192.168.90.11 with valid X-Device-Secret
     * and an existing employee's RFID card / NIK.
     * Assert response: HTTP 200 / 201.
     * Assert database: record added in access_logs with status = 'Granted'.
     */
    public function test_case_a_valid_tap_granted(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.11'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
                'verify_method' => 'Fingerprint',
                'access_status' => 'Granted',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'access_status' => 'Granted',
                    'employee_name' => 'Budi Santoso',
                ],
            ]);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'employee_id' => $this->employee->id,
            'verify_method' => 'Fingerprint',
            'access_status' => 'Granted',
        ]);
    }

    /**
     * Case B (Unknown Card): Send payload with unrecognized card number UNKNOWN_CARD_999.
     * Assert response: HTTP 200 / 201 processed.
     * Assert database: record added in access_logs with employee_id = null and status = 'Denied'.
     */
    public function test_case_b_unknown_card_denied(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.11'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'card_number' => 'UNKNOWN_CARD_999',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'access_status' => 'Denied',
                    'employee_name' => 'Unknown / Unregistered Card',
                ],
            ]);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'employee_id' => null,
            'access_status' => 'Denied',
        ]);
    }

    /**
     * Case C (Unauthorized Intrusion): Send payload with invalid secret token or from an untrusted origin.
     * Assert response: HTTP 401 or 403 (blocked by VerifyDeviceWebhook).
     */
    public function test_case_c_unauthorized_intrusion_invalid_secret(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.11'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
            ], [
                'X-Device-Secret' => 'invalid_hacker_secret_token',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 403,
            ]);
    }

    public function test_case_c_unauthorized_intrusion_untrusted_origin_ip(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.200.5.99'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'status' => 'error',
                'code' => 403,
            ]);
    }
}
