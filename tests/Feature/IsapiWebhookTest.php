<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Door;
use App\Models\Employee;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
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
        Log::spy();

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

        Log::shouldHaveReceived('info')
            ->with('[ISAPI Webhook] Access event recorded', \Mockery::on(function (array $context) {
                return $context['door_id'] === 'DOOR-A'
                    && $context['access_status'] === 'Granted'
                    && !array_key_exists('card_number', $context)
                    && !array_key_exists('authorization', $context);
            }))
            ->once();
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
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
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

    public function test_public_172_address_is_not_trusted_as_proxy(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '172.15.0.1'])
            ->postJson('/api/v1/isapi/event-notification?door_id=DOOR-A', [
                'user' => 'NIK-882101',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ])->assertStatus(403);
    }

    public function test_forwarded_device_ip_from_untrusted_peer_is_rejected(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->withHeader('X-Forwarded-For', '192.168.90.11')
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ])->assertStatus(403);
    }

    public function test_valid_hardware_event_with_invalid_secret_fails_closed(): void
    {
        $xml = '<EventNotificationAlert><ipAddress>192.168.90.11</ipAddress><majorEventType>1</majorEventType><subEventType>1</subEventType></EventNotificationAlert>';

        $this->call('POST', '/api/v1/isapi/event-notification?door_id=DOOR-A', [], [], [], [
            'REMOTE_ADDR' => '192.168.90.11',
            'HTTP_X_DEVICE_SECRET' => 'invalid',
            'CONTENT_TYPE' => 'application/xml',
        ], $xml)->assertStatus(403);
    }

    public function test_wildcard_sanctum_token_cannot_submit_hardware_events(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'web@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
        $token = $admin->createToken('web-session-token')->plainTextToken;

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.11'])
            ->withToken($token)
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
            ])->assertStatus(403);
    }

    public function test_device_scoped_sanctum_token_can_submit_hardware_events(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'device@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
        $token = $admin->createToken('device-token-DOOR-A', ['device:push-log'])->plainTextToken;

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.11'])
            ->withToken($token)
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
                'access_status' => 'Granted',
            ])->assertStatus(200);
    }

    public function test_device_scoped_token_cannot_submit_for_another_door(): void
    {
        $admin = Admin::create([
            'name' => 'Super Admin',
            'email' => 'bound-device@example.test',
            'password' => bcrypt('password'),
            'role' => 'super_admin',
        ]);
        $token = $admin->createToken('device-token-DOOR-B', ['device:push-log'])->plainTextToken;

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.11'])
            ->withToken($token)
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
            ])->assertStatus(403);
    }

    public function test_door_secret_cannot_authorize_another_door(): void
    {
        Door::create([
            'door_id' => 'DOOR-B',
            'door_name' => 'Door B',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
        ]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.15'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-B',
                'user' => 'NIK-882101',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ])->assertStatus(403);
    }

    /**
     * Test parsing real XML payload for normal tap access.
     */
    public function test_xml_event_notification_standard_tap_granted(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<EventNotificationAlert version="2.0" xmlns="http://www.hikvision.com/ver20/XMLSchema">
    <ipAddress>192.168.90.11</ipAddress>
    <dateTime>2026-09-08T15:10:00+07:00</dateTime>
    <AccessControllerEvent>
        <majorEventType>1</majorEventType>
        <subEventType>1</subEventType>
        <cardNo>CARD-1001</cardNo>
        <employeeNoString>USR-1001</employeeNoString>
    </AccessControllerEvent>
</EventNotificationAlert>
XML;

        $response = $this->call(
            'POST',
            '/api/v1/isapi/event-notification',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '192.168.90.11',
                'HTTP_X_DEVICE_SECRET' => 'secret_door_a_9981',
                'CONTENT_TYPE' => 'application/xml',
            ],
            $xml
        );

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'access_status' => 'Granted',
                    'door_id' => 'DOOR-A',
                    'employee_name' => 'Budi Santoso',
                ],
            ]);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'employee_id' => $this->employee->id,
            'verify_method' => 'Card',
            'access_status' => 'Granted',
        ]);
    }

    /**
     * Test parsing real XML payload for major 5 DOOR_FORCED_OPEN alarm.
     */
    public function test_xml_event_notification_door_forced_open_alarm(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<EventNotificationAlert version="2.0">
    <dateTime>2026-09-08T15:11:00+07:00</dateTime>
    <majorEventType>5</majorEventType>
    <subEventType>21</subEventType>
</EventNotificationAlert>
XML;

        $response = $this->call(
            'POST',
            '/api/v1/isapi/event-notification',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '192.168.90.11',
                'HTTP_X_DEVICE_SECRET' => 'secret_door_a_9981',
                'CONTENT_TYPE' => 'application/xml',
            ],
            $xml
        );

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'event_type' => 'DOOR_FORCED_OPEN',
                    'access_status' => 'Alarm',
                ],
            ]);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'event_type' => 'DOOR_FORCED_OPEN',
            'access_status' => 'Alarm',
        ]);
    }

    /**
     * Test parsing real XML payload for major 5 TAMPER_ALARM.
     */
    public function test_xml_event_notification_tamper_alarm(): void
    {
        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<EventNotificationAlert version="2.0">
    <dateTime>2026-09-08T15:12:00+07:00</dateTime>
    <majorEventType>5</majorEventType>
    <subEventType>38</subEventType>
</EventNotificationAlert>
XML;

        $response = $this->call(
            'POST',
            '/api/v1/isapi/event-notification',
            [],
            [],
            [],
            [
                'REMOTE_ADDR' => '192.168.90.11',
                'HTTP_X_DEVICE_SECRET' => 'secret_door_a_9981',
                'CONTENT_TYPE' => 'application/xml',
            ],
            $xml
        );

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'data' => [
                    'event_type' => 'TAMPER_ALARM',
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
     * Test proxy + door_id parameter
     */
    public function test_trusted_proxy_with_door_id_parameter(): void
    {
        config(['services.hikvision.allowed_device_ips' => '172.25.0.1']);

        $response = $this->withServerVariables(['REMOTE_ADDR' => '172.25.0.1'])
            ->postJson('/api/v1/isapi/event-notification?door_id=DOOR-A', [
                'user' => 'NIK-882101',
                'verify_method' => 'Fingerprint',
                'access_status' => 'Granted',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.door_id', 'DOOR-A');
    }

    /**
     * Test untrusted source + door_id parameter
     */
    public function test_untrusted_source_with_door_id_parameter_rejected(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])
            ->postJson('/api/v1/isapi/event-notification?door_id=DOOR-A', [
                'user' => 'NIK-882101',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Test deduplication logic
     */
    public function test_duplicate_serial_no_retry(): void
    {
        // First request
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.11'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
                'serial_no' => '123456',
                'event_type' => 'STANDARD_TAP',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ]);

        $countBefore = AccessLog::count();

        // Duplicate request
        $response = $this->withServerVariables(['REMOTE_ADDR' => '192.168.90.11'])
            ->postJson('/api/v1/isapi/event-notification', [
                'door_id' => 'DOOR-A',
                'user' => 'NIK-882101',
                'serial_no' => '123456',
                'event_type' => 'STANDARD_TAP',
            ], [
                'X-Device-Secret' => 'secret_door_a_9981',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Event duplikat diabaikan');

        $this->assertEquals($countBefore, AccessLog::count(), 'AccessLog should not duplicate');
    }
}
