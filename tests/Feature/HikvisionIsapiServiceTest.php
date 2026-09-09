<?php

namespace Tests\Feature;

use App\Models\Door;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionIsapiServiceTest extends TestCase
{
    use RefreshDatabase;

    protected HikvisionIsapiService $service;
    protected Door $door;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new HikvisionIsapiService();

        $this->door = Door::create([
            'door_id' => 'DOOR-A',
            'door_name' => 'Door A - Gedung Utama',
            'location' => 'Gedung A',
            'device_ip' => '192.168.90.11',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);
    }

    /**
     * Test URL building in mock mode vs real device mode.
     */
    public function test_build_url_generates_correct_paths(): void
    {
        // 1. In mock mode
        Config::set('services.hikvision.use_mock', true);
        Config::set('services.hikvision.mock_base_url', 'http://localhost:8000/api/mock/isapi');

        $this->assertEquals(
            'http://localhost:8000/api/mock/isapi/System/status',
            $this->service->buildUrl('/System/status')
        );

        // 2. In real device mode
        Config::set('services.hikvision.use_mock', false);
        $this->assertEquals(
            'http://192.168.90.11/ISAPI/System/status',
            $this->service->buildUrl('/System/status', $this->door)
        );
    }

    /**
     * Test mock mode directly invokes HikvisionMockController internally (bypassing cURL deadlock).
     */
    public function test_mock_mode_invokes_controller_internally_without_http_calls(): void
    {
        Config::set('services.hikvision.use_mock', true);

        // 1. getDeviceStatus
        $status = $this->service->getDeviceStatus($this->door);
        $this->assertTrue($status['status']);
        $this->assertEquals(1, $status['statusCode']);
        $this->assertEquals('closed', $status['data']['doorStatus']);

        // 2. syncCardUser success
        $sync = $this->service->syncCardUser('USR-1001', 'CARD-1001', 'Budi Santoso', $this->door);
        $this->assertTrue($sync['status']);
        $this->assertEquals(1, $sync['statusCode']);
        $this->assertEquals('CARD-1001', $sync['data']['cardNo']);

        // 3. syncCardUser validation error
        $syncErr = $this->service->syncCardUser('', '', null, $this->door);
        $this->assertFalse($syncErr['status']);
        $this->assertEquals(400, $syncErr['statusCode']);
        $this->assertStringContainsString('cardNo and employeeNo are required', $syncErr['error']);

        // 4. fetchEvents
        $events = $this->service->fetchEvents(10, $this->door);
        $this->assertTrue($events['status']);
        $this->assertEquals(1, $events['statusCode']);
        $this->assertNotEmpty($events['events']);
        $this->assertEquals('Card', $events['events'][0]['verify_method']);
    }

    /**
     * Test getDeviceStatus successfully receives device status in real HTTP mode.
     */
    public function test_get_device_status_returns_success_response(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/System/deviceInfo*' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
                'DeviceInfo' => [
                    'model' => 'DS-K1T804AMF',
                    'serialNumber' => 'DS-K1T804AMF20260901',
                    'firmwareVersion' => 'V1.2.3',
                    'doorStatus' => 'closed',
                ],
                'status' => 'OK',
                'online' => true,
                'doorStatus' => 'closed',
            ], 200),
        ]);

        $result = $this->service->getDeviceStatus($this->door);

        $this->assertTrue($result['status']);
        $this->assertEquals(1, $result['statusCode']);
        $this->assertArrayHasKey('data', $result);
        $this->assertEquals('closed', $result['data']['doorStatus']);
        $this->assertEquals('DS-K1T804AMF', $result['data']['model']);
        $this->assertEquals('DS-K1T804AMF20260901', $result['data']['serialNumber']);
        $this->assertEquals('V1.2.3', $result['data']['firmware']);
    }

    /**
     * Test getDeviceStatus successfully parses raw XML response from physical Hikvision terminal.
     */
    public function test_get_device_status_parses_xml_response_successfully(): void
    {
        Config::set('services.hikvision.use_mock', false);

        $xmlPayload = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<DeviceInfo version="2.0" xmlns="http://www.hikvision.com/ver20/XMLSchema">
    <deviceName>Access Controller</deviceName>
    <deviceID>123456</deviceID>
    <model>DS-K1T804AMF</model>
    <serialNumber>DS-K1T804AMF20260901V010203EN123</serialNumber>
    <macAddress>44:19:b6:aa:bb:cc</macAddress>
    <firmwareVersion>V1.2.3 build 260901</firmwareVersion>
    <firmwareReleasedDate>2026-09-01</firmwareReleasedDate>
    <deviceType>AccessControl</deviceType>
    <doorStatus>closed</doorStatus>
</DeviceInfo>
XML;

        Http::fake([
            '*/System/deviceInfo*' => Http::response($xmlPayload, 200, ['Content-Type' => 'application/xml']),
        ]);

        $result = $this->service->getDeviceStatus($this->door);

        $this->assertTrue($result['status']);
        $this->assertEquals(1, $result['statusCode']);
        $this->assertEquals('DS-K1T804AMF', $result['data']['model']);
        $this->assertEquals('DS-K1T804AMF20260901V010203EN123', $result['data']['serialNumber']);
        $this->assertEquals('V1.2.3 build 260901', $result['data']['firmware']);
        $this->assertEquals('closed', $result['data']['doorStatus']);
        $this->assertTrue($result['data']['online']);
    }

    /**
     * Test getDeviceStatus handles connection failure without crashing.
     */
    public function test_get_device_status_handles_connection_exception(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/System/deviceInfo*' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $result = $this->service->getDeviceStatus($this->door);

        $this->assertFalse($result['status']);
        $this->assertEquals(500, $result['statusCode']);
        $this->assertStringContainsString('Connection timed out', $result['error']);
    }

    /**
     * Test syncCardUser successfully synchronizes card data in real HTTP mode.
     */
    public function test_sync_card_user_success(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/CardInfo/Record' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
                'subStatusCode' => 'ok',
                'ResponseStatus' => [
                    'requestURL' => '/ISAPI/AccessControl/CardInfo/Record',
                    'statusCode' => 1,
                    'statusString' => 'OK',
                ],
                'cardNo' => 'CARD-100234',
                'employeeNo' => 'USR-1001',
            ], 200),
        ]);

        $result = $this->service->syncCardUser('USR-1001', 'CARD-100234', 'Budi Santoso', $this->door);

        $this->assertTrue($result['status']);
        $this->assertEquals(1, $result['statusCode']);
        $this->assertNull($result['error']);
        $this->assertEquals('CARD-100234', $result['data']['cardNo']);
    }

    /**
     * Test syncCardUser handles bad request validation error (400) in real HTTP mode.
     */
    public function test_sync_card_user_handles_validation_error(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/CardInfo/Record' => Http::response([
                'statusCode' => 4,
                'statusString' => 'Invalid Operation',
                'subStatusCode' => 'badParameters',
                'errorMsg' => 'cardNo and employeeNo are required.',
                'ResponseStatus' => [
                    'requestURL' => '/ISAPI/AccessControl/CardInfo/Record',
                    'statusCode' => 4,
                    'statusString' => 'Invalid Operation',
                    'subStatusCode' => 'badParameters',
                    'errorMsg' => 'cardNo and employeeNo are required.',
                ],
            ], 400),
        ]);

        $result = $this->service->syncCardUser('', '', null, $this->door);

        $this->assertFalse($result['status']);
        $this->assertEquals(400, $result['statusCode']);
        $this->assertStringContainsString('cardNo and employeeNo are required', $result['error']);
    }

    /**
     * Test syncCardUser handles network timeout exception gracefully in real HTTP mode.
     */
    public function test_sync_card_user_handles_exception_resiliently(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/CardInfo/Record' => function () {
                throw new \Exception('Network unreachable');
            },
        ]);

        $result = $this->service->syncCardUser('USR-1001', 'CARD-100234', 'Budi Santoso', $this->door);

        $this->assertFalse($result['status']);
        $this->assertEquals(500, $result['statusCode']);
        $this->assertStringContainsString('Network unreachable', $result['error']);
    }

    /**
     * Test fetchEvents retrieves and standardizes access events in real HTTP mode.
     */
    public function test_fetch_events_returns_formatted_logs(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/AcsEvent' => Http::response([
                'statusCode' => 1,
                'statusString' => 'OK',
                'AcsEvent' => [
                    'searchID' => '1',
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 2,
                    'totalMatches' => 2,
                    'InfoList' => [
                        [
                            'major' => 5,
                            'minor' => 1,
                            'time' => '2026-09-07T08:15:30+07:00',
                            'cardNo' => 'CARD-100234',
                            'employeeNoString' => 'EMP-001',
                            'name' => 'Budi Santoso',
                            'verifyMethod' => 'Card',
                            'doorNo' => 1,
                            'doorName' => 'Pintu Utama (DOOR-A)',
                            'accessStatus' => 'Granted',
                        ],
                        [
                            'major' => 5,
                            'minor' => 2,
                            'time' => '2026-09-07T08:45:10+07:00',
                            'cardNo' => '',
                            'employeeNoString' => 'EMP-002',
                            'name' => 'Siti Rahma',
                            'verifyMethod' => 'Fingerprint',
                            'doorNo' => 1,
                            'doorName' => 'Pintu Utama (DOOR-A)',
                            'accessStatus' => 'Granted',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $result = $this->service->fetchEvents(10, $this->door);

        $this->assertTrue($result['status']);
        $this->assertEquals(1, $result['statusCode']);
        $this->assertEquals(2, $result['total']);
        $this->assertCount(2, $result['events']);

        $firstEvent = $result['events'][0];
        $this->assertEquals('CARD-100234', $firstEvent['card_no']);
        $this->assertEquals('EMP-001', $firstEvent['employee_no']);
        $this->assertEquals('Card', $firstEvent['verify_method']);
        $this->assertEquals('Granted', $firstEvent['access_status']);

        $secondEvent = $result['events'][1];
        $this->assertEquals('Fingerprint', $secondEvent['verify_method']);
    }

    /**
     * Test fetchEvents handles error response without throwing unhandled 500 in real HTTP mode.
     */
    public function test_fetch_events_handles_error(): void
    {
        Config::set('services.hikvision.use_mock', false);

        Http::fake([
            '*/AccessControl/AcsEvent' => Http::response([
                'statusCode' => 7,
                'statusString' => 'Device Busy',
                'errorMsg' => 'Device busy processing another search.',
            ], 503),
        ]);

        $result = $this->service->fetchEvents(10, $this->door);

        $this->assertFalse($result['status']);
        $this->assertEquals(503, $result['statusCode']);
        $this->assertEquals(0, $result['total']);
        $this->assertEmpty($result['events']);
        $this->assertStringContainsString('Device busy', $result['error']);
    }

    /**
     * Test setUser and pingDevice backward compatibility.
     */
    public function test_backward_compatible_methods(): void
    {
        Config::set('services.hikvision.use_mock', false);

        $employee = Employee::create([
            'employee_id' => 'USR-1001',
            'nik' => 'NIK-882101',
            'name' => 'Budi Santoso',
            'card_no' => 'CARD-1001',
            'department' => 'IT Support',
        ]);

        Http::fake([
            '*/System/deviceInfo*' => Http::response(['statusCode' => 1, 'status' => 'OK'], 200),
            '*/AccessControl/CardInfo/Record' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
        ]);

        // pingDevice
        $isOnline = $this->service->pingDevice($this->door);
        $this->assertTrue($isOnline);

        // setUser
        $userResult = $this->service->setUser($this->door, $employee);
        $this->assertTrue($userResult['status']);
    }

    /**
     * Test credentials resolution strictly uses Laravel config without direct runtime env calls.
     */
    public function test_credentials_resolved_from_config_per_door(): void
    {
        Config::set('services.doors.DOOR-B.username', 'admin_door_b');
        Config::set('services.doors.DOOR-B.password', 'CustomPassDoorB123');

        $doorB = new Door(['door_id' => 'DOOR-B', 'device_ip' => '192.168.90.15']);
        $creds = $this->service->getDeviceCredentials($doorB);

        $this->assertEquals('admin_door_b', $creds['username']);
        $this->assertEquals('CustomPassDoorB123', $creds['password']);

        // Default fallback
        $credsDefault = $this->service->getDeviceCredentials(null);
        $this->assertEquals(config('services.hikvision.username'), $credsDefault['username']);
        $this->assertEquals(config('services.hikvision.password'), $credsDefault['password']);
    }

    /**
     * Test device host and port resolution from config.
     */
    public function test_device_host_and_port_resolved_from_config(): void
    {
        Config::set('services.doors.DOOR-C.ip', '192.168.90.13');
        Config::set('services.hikvision.port', 8088);

        $doorC = new Door(['door_id' => 'DOOR-C']);
        $hostPort = $this->service->getDeviceHostAndPort($doorC);

        $this->assertEquals('192.168.90.13', $hostPort['host']);
        $this->assertEquals(8088, $hostPort['port']);
    }
}
