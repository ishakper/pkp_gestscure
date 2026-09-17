<?php

namespace Tests\Feature;

use App\Events\AccessLogCreated;
use App\Models\AccessLog;
use App\Models\AttendanceEvidence;
use App\Models\Door;
use App\Models\Employee;
use App\Services\AttendanceProcessor;
use App\Services\HikvisionAlertStreamClient;
use App\Services\HikvisionEventIngestionService;
use App\Services\HikvisionPayloadParser;
use App\Services\HikvisionStreamLeaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HikvisionAlertStreamTest extends TestCase
{
    use RefreshDatabase;

    protected Door $door;
    protected Employee $employee;
    protected Employee $employee2;
    protected HikvisionAlertStreamClient $streamClient;
    protected HikvisionEventIngestionService $ingestionService;
    protected HikvisionStreamLeaseManager $leaseManager;

    protected function setUp(): void
    {
        parent::setUp();

        HikvisionAlertStreamClient::$streamHandler = null;

        $this->door = Door::create([
            'door_id' => 'DOOR-B',
            'door_name' => 'Door B - Lab R&D',
            'location' => 'Gedung B Lt 2',
            'device_ip' => '192.168.90.15',
            'device_model' => 'DS-K1T804AMF',
            'connection_status' => 'online',
        ]);

        $this->employee = Employee::create([
            'employee_id' => 'EMP-2002',
            'nik' => 'NIK-772202',
            'name' => 'Siti Aminah',
            'card_no' => 'CARD-998877',
            'department' => 'Engineering',
        ]);

        $this->employee2 = Employee::create([
            'employee_id' => 'EMP-2003',
            'nik' => 'NIK-772203',
            'name' => 'Ahmad Dahlan',
            'card_no' => 'CARD-112233',
            'department' => 'Operations',
        ]);

        $this->streamClient = app(HikvisionAlertStreamClient::class);
        $this->ingestionService = app(HikvisionEventIngestionService::class);
        $this->leaseManager = app(HikvisionStreamLeaseManager::class);
    }

    protected function tearDown(): void
    {
        HikvisionAlertStreamClient::$streamHandler = null;
        Cache::forget("hikvision-alertstream:DOOR-B");
        Cache::forget(HikvisionStreamLeaseManager::key('DOOR-B'));
        Cache::forget("hikvision-alertstream:health:DOOR-B");
        parent::tearDown();
    }

    /** 1. Valid stream standard tap granted creates AccessLog */
    public function test_1_valid_stream_standard_tap_granted_creates_access_log(): void
    {
        $payload = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'verify_method' => 'Card',
            'device_ip' => $this->door->device_ip,
            'timestamp' => now()->toIso8601String(),
            'source_format' => 'ALERTSTREAM_MULTIPART',
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(200, $result['code']);
        $this->assertEquals('Granted', $result['data']['access_status']);
        $this->assertEquals($this->employee->name, $result['data']['employee_name']);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'STANDARD_TAP',
            'access_status' => 'Granted',
            'source' => 'HIKVISION_ALERTSTREAM',
        ]);
    }

    /** 2. Stream tap triggers attendance evidence processing */
    public function test_2_stream_tap_triggers_attendance_evidence_processing(): void
    {
        $payload = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'verify_method' => 'Card',
            'device_ip' => $this->door->device_ip,
            'timestamp' => now()->toIso8601String(),
            'direction' => 'ENTRY',
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');
        $logId = $result['data']['log_id'];

        $accessLog = AccessLog::where('log_id', $logId)->first();
        $this->assertNotNull($accessLog);

        $evidence = AttendanceEvidence::where('access_log_id', $accessLog->id)->first();
        $this->assertNotNull($evidence, 'AttendanceEvidence must be created by ProcessAccessLogForAttendance listener');
        $this->assertEquals($this->employee->id, $evidence->employee_id);
        $this->assertEquals('ENTRY', $evidence->direction);
    }

    /** 3. Valid stream door forced open alarm */
    public function test_3_valid_stream_door_forced_open_alarm(): void
    {
        $payload = [
            'event_type' => 'DOOR_FORCED_OPEN',
            'device_ip' => $this->door->device_ip,
            'timestamp' => now()->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Alarm', $result['data']['access_status']);
        $this->assertStringContainsString('Door Forced Open', $result['data']['reason']);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'event_type' => 'DOOR_FORCED_OPEN',
            'access_status' => 'Alarm',
        ]);
    }

    /** 4. Valid stream tamper alarm */
    public function test_4_valid_stream_tamper_alarm(): void
    {
        $payload = [
            'event_type' => 'TAMPER_ALARM',
            'device_ip' => $this->door->device_ip,
            'timestamp' => now()->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Alarm', $result['data']['access_status']);
        $this->assertStringContainsString('Tamper Alarm', $result['data']['reason']);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'event_type' => 'TAMPER_ALARM',
            'access_status' => 'Alarm',
        ]);
    }

    /** 5. Valid stream duress fingerprint */
    public function test_5_valid_stream_duress_fingerprint(): void
    {
        $payload = [
            'user' => $this->employee->nik,
            'event_type' => 'DURESS_FINGERPRINT',
            'device_ip' => $this->door->device_ip,
            'timestamp' => now()->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Duress', $result['data']['access_status']);

        $this->assertDatabaseHas('access_logs', [
            'door_id' => $this->door->id,
            'employee_id' => $this->employee->id,
            'event_type' => 'DURESS_FINGERPRINT',
            'access_status' => 'Duress',
        ]);
    }

    /** 6. Stream unknown card denied and raw card not stored in nik */
    public function test_6_stream_unknown_card_denied_and_raw_card_privacy(): void
    {
        $rawCard = 'SECRET-RAW-CARD-999888';
        $payload = [
            'card_no' => $rawCard,
            'event_type' => 'STANDARD_TAP',
            'device_ip' => $this->door->device_ip,
            'timestamp' => now()->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');

        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Denied', $result['data']['access_status']);

        $log = AccessLog::where('log_id', $result['data']['log_id'])->first();
        $this->assertNotNull($log);
        $this->assertNull($log->employee_id);
        $this->assertNull($log->nik, 'Raw card must NOT be persisted into nik field');
        $this->assertStringNotContainsString($rawCard, (string) $log->reason, 'Raw card must not be in reason');
        $this->assertStringNotContainsString($rawCard, json_encode($result['data']), 'Raw card must not be in response');
    }

    /** 7. Verification method normalization */
    public function test_7_verification_method_normalization(): void
    {
        $methods = [
            'card' => 'Card',
            'fingerPrint' => 'Fingerprint',
            'face' => 'Face',
            'password' => 'Password',
        ];

        foreach ($methods as $raw => $normalized) {
            $payload = [
                'card_no' => $this->employee->card_no,
                'event_type' => 'STANDARD_TAP',
                'verify_method' => $raw,
                'serial_no' => 'SERIAL-METH-' . $raw,
                'device_ip' => $this->door->device_ip,
            ];

            $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');
            $this->assertEquals('success', $result['status']);

            $log = AccessLog::where('log_id', $result['data']['log_id'])->first();
            $this->assertEquals($normalized, $log->verify_method);
        }
    }

    /** 8. Multiple events in single chunk */
    public function test_8_multiple_events_in_single_chunk(): void
    {
        $part1 = "Content-Type: application/json\r\n\r\n{\"AccessControllerEvent\":{\"majorEventType\":5,\"subEventType\":1,\"cardNo\":\"CARD-998877\",\"serialNo\":\"CHUNK-1\"}}";
        $part2 = "Content-Type: application/json\r\n\r\n{\"AccessControllerEvent\":{\"majorEventType\":5,\"subEventType\":1,\"cardNo\":\"CARD-998877\",\"serialNo\":\"CHUNK-2\"}}";
        $chunk = "--boundary\r\n{$part1}\r\n--boundary\r\n{$part2}\r\n--boundary\r\n";

        $buffer = $chunk;
        $boundary = 'boundary';
        $initialized = false;

        $parts = $this->streamClient->extractParts($buffer, $boundary, $initialized);

        $this->assertCount(2, $parts);

        $parsed1 = $this->streamClient->parsePart($parts[0]);
        $parsed2 = $this->streamClient->parsePart($parts[1]);

        $this->assertNotNull($parsed1);
        $this->assertNotNull($parsed2);
        $this->assertStringContainsString('CHUNK-1', $parsed1['body']);
        $this->assertStringContainsString('CHUNK-2', $parsed2['body']);
    }

    /** 9. Single event split across multiple chunks */
    public function test_9_single_event_split_across_multiple_chunks(): void
    {
        $chunk1 = "--bound";
        $chunk2 = "ary\r\nContent-Type: application/json\r\n\r\n{\"AccessControllerEvent\":{\"majorEventType\":5,\"subEventType\":1,\"cardNo\":\"CARD-99";
        $chunk3 = "8877\"}}\r\n--boundary\r\n";

        $buffer = '';
        $boundary = 'boundary';
        $initialized = false;

        $buffer .= $chunk1;
        $parts1 = $this->streamClient->extractParts($buffer, $boundary, $initialized);
        $this->assertEmpty($parts1);

        $buffer .= $chunk2;
        $parts2 = $this->streamClient->extractParts($buffer, $boundary, $initialized);
        $this->assertEmpty($parts2);

        $buffer .= $chunk3;
        $parts3 = $this->streamClient->extractParts($buffer, $boundary, $initialized);
        $this->assertCount(1, $parts3);

        $parsed = $this->streamClient->parsePart($parts3[0]);
        $this->assertNotNull($parsed);
        $this->assertStringContainsString('CARD-998877', $parsed['body']);
    }

    /** 10. Buffer overflow protection resets on 1MB limit */
    public function test_10_buffer_overflow_protection_resets_on_1mb_limit(): void
    {
        $hugeBuffer = str_repeat('A', HikvisionAlertStreamClient::MAX_BUFFER_SIZE + 100);
        $boundary = 'boundary';
        $initialized = false;

        $parts = $this->streamClient->extractParts($hugeBuffer, $boundary, $initialized);

        $this->assertEmpty($parts);
        $this->assertEmpty($hugeBuffer, 'Buffer must be reset to empty string when exceeding MAX_BUFFER_SIZE');
    }

    /** 11. Boundary detection from custom Content-Type */
    public function test_11_boundary_detection_from_custom_content_type(): void
    {
        $ct1 = 'multipart/mixed; boundary=MIME_boundary_12345';
        $this->assertEquals('MIME_boundary_12345', $this->streamClient->detectBoundary($ct1));

        $ct2 = 'multipart/mixed; boundary="custom-quoted-boundary"';
        $this->assertEquals('custom-quoted-boundary', $this->streamClient->detectBoundary($ct2));

        $ct3 = 'text/plain';
        $this->assertEquals('boundary', $this->streamClient->detectBoundary($ct3));
    }

    /** 12. Empty parts and preamble ignored */
    public function test_12_empty_parts_and_preamble_ignored(): void
    {
        $stream = "HTTP preamble info\r\n--boundary\r\n\r\n--boundary\r\n   \r\n--boundary\r\n--";
        $buffer = $stream;
        $boundary = 'boundary';
        $initialized = false;

        $parts = $this->streamClient->extractParts($buffer, $boundary, $initialized);
        $this->assertEmpty($parts);
    }

    /** 13. Hardware serial deduplication within 5 minutes */
    public function test_13_hardware_serial_deduplication(): void
    {
        $payload1 = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'serial_no' => 'SERIAL-TEST-DUP-01',
            'device_ip' => $this->door->device_ip,
        ];

        $res1 = $this->ingestionService->ingest($payload1, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res1['status']);
        $this->assertNotEquals('Event duplikat diabaikan', $res1['message']);

        // Duplicate event with same serial and employee
        $res2 = $this->ingestionService->ingest($payload1, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res2['status']);
        $this->assertEquals('Event duplikat diabaikan', $res2['message']);
        $this->assertEquals($res1['data']['log_id'], $res2['data']['log_id']);
    }

    /** 14. Cross-channel fingerprint deduplication within window */
    public function test_14_cross_channel_fingerprint_deduplication(): void
    {
        $timestamp = now()->toIso8601String();

        // 1. Webhook channel
        $webhookPayload = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $timestamp,
            'device_ip' => $this->door->device_ip,
        ];
        $res1 = $this->ingestionService->ingest($webhookPayload, $this->door, 'HIKVISION_WEBHOOK');
        $this->assertEquals('success', $res1['status']);

        // 2. Alertstream channel within same timestamp
        $streamPayload = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $timestamp,
            'device_ip' => $this->door->device_ip,
        ];
        $res2 = $this->ingestionService->ingest($streamPayload, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res2['status']);
        $this->assertEquals('Event duplikat diabaikan', $res2['message']);
        $this->assertEquals(1, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 15. Two legitimate events from same device within 5 minutes both persist */
    public function test_15_two_legitimate_events_from_same_device_within_5_minutes_both_persist(): void
    {
        $now = now();

        // Event 1: Employee 1
        $payload1 = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $now->toIso8601String(),
            'device_ip' => $this->door->device_ip,
            'serial_no' => 'DEV-TERMINAL-SN-001',
        ];
        $res1 = $this->ingestionService->ingest($payload1, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res1['status']);
        $this->assertFalse($res1['duplicate']);

        // Event 2: Employee 2 (same door, same serial_no/device_serial, 30s later)
        $payload2 = [
            'card_no' => $this->employee2->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $now->copy()->addSeconds(30)->toIso8601String(),
            'device_ip' => $this->door->device_ip,
            'serial_no' => 'DEV-TERMINAL-SN-001',
        ];
        $res2 = $this->ingestionService->ingest($payload2, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res2['status']);
        $this->assertFalse($res2['duplicate'], 'Different employee on same device must NOT be collapsed');

        $this->assertEquals(2, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 16. Same employee tapping twice at distinct timestamps both persist */
    public function test_16_same_employee_tapping_twice_at_distinct_timestamps_both_persist(): void
    {
        $now = now();

        // Tap 1
        $payload1 = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $now->toIso8601String(),
            'device_ip' => $this->door->device_ip,
        ];
        $res1 = $this->ingestionService->ingest($payload1, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res1['status']);
        $this->assertFalse($res1['duplicate']);

        // Tap 2: 30s later
        $payload2 = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $now->copy()->addSeconds(30)->toIso8601String(),
            'device_ip' => $this->door->device_ip,
        ];
        $res2 = $this->ingestionService->ingest($payload2, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res2['status']);
        $this->assertFalse($res2['duplicate'], 'Same employee at distinct timestamps must both persist');

        $this->assertEquals(2, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 17. Device serial alone cannot collapse legitimate distinct events */
    public function test_17_device_serial_alone_cannot_collapse_distinct_events(): void
    {
        $now = now();

        $payload1 = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $now->toIso8601String(),
            'serial_no' => 'FIXED-DEVICE-ID-999',
            'device_ip' => $this->door->device_ip,
        ];
        $res1 = $this->ingestionService->ingest($payload1, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res1['status']);
        $this->assertFalse($res1['duplicate']);

        $payload2 = [
            'card_no' => $this->employee2->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $now->copy()->addSeconds(30)->toIso8601String(),
            'serial_no' => 'FIXED-DEVICE-ID-999',
            'device_ip' => $this->door->device_ip,
        ];
        $res2 = $this->ingestionService->ingest($payload2, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res2['status']);
        $this->assertFalse($res2['duplicate']);
        $this->assertEquals(2, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 18. Exact replay persists once */
    public function test_18_exact_replay_persists_once(): void
    {
        $now = now()->toIso8601String();

        $payload = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $now,
            'serial_no' => 'REPLAY-SEQ-100',
            'device_ip' => $this->door->device_ip,
        ];

        $res1 = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res1['status']);
        $this->assertFalse($res1['duplicate']);

        $res2 = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('success', $res2['status']);
        $this->assertTrue($res2['duplicate']);

        $res3 = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertTrue($res3['duplicate']);

        $this->assertEquals(1, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 19. Privacy: full card and secrets not exposed in logs */
    public function test_19_privacy_full_card_and_secrets_not_exposed_in_logs(): void
    {
        Log::spy();

        $payload = [
            'card_no' => $this->employee->card_no,
            'event_type' => 'STANDARD_TAP',
            'device_ip' => $this->door->device_ip,
            'secret' => 'SECRET-SUPER-TOP',
        ];

        $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM');

        Log::shouldHaveReceived('info')
            ->with('[Hikvision Event Ingested]', \Mockery::on(function (array $context) {
                return !array_key_exists('card_no', $context)
                    && !array_key_exists('card_number', $context)
                    && !array_key_exists('secret', $context);
            }));
    }

    /** 20. Blocker 1: Heartbeat/status messages create zero AccessLog rows */
    public function test_20_heartbeat_messages_create_zero_access_logs(): void
    {
        $heartbeatXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<EventNotificationAlert version="2.0">
    <ipAddress>192.168.90.15</ipAddress>
    <dateTime>2026-09-17T08:00:00+08:00</dateTime>
    <eventType>heartBeat</eventType>
</EventNotificationAlert>
XML;

        $parsed = HikvisionPayloadParser::parse($heartbeatXml, 'application/xml');
        $this->assertNull($parsed, 'Heartbeat XML must parse to null');

        $heartbeatJson = json_encode([
            'ipAddress' => '192.168.90.15',
            'eventType' => 'heartBeat',
            'dateTime' => '2026-09-17T08:00:00+08:00',
        ]);
        $parsedJson = HikvisionPayloadParser::parse($heartbeatJson, 'application/json');
        $this->assertNull($parsedJson, 'Heartbeat JSON must parse to null');
    }

    /** 21. Defense-in-depth: Structurally empty stream event rejected safely */
    public function test_21_defense_in_depth_structurally_empty_event_rejected(): void
    {
        $emptyPayload = [
            'device_ip' => $this->door->device_ip,
            'timestamp' => now()->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($emptyPayload, $this->door, 'HIKVISION_ALERTSTREAM');
        $this->assertEquals('ignored', $result['status']);
        $this->assertEquals(422, $result['code']);

        $this->assertEquals(0, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 22. Blocker 4: Oversized complete part (>1MB) discarded safely */
    public function test_22_oversized_complete_part_discarded_safely(): void
    {
        $hugeData = str_repeat('X', HikvisionAlertStreamClient::MAX_PART_SIZE + 500);
        $rawPart = "Content-Type: text/plain\r\n\r\n" . $hugeData;

        $parsed = $this->streamClient->parsePart($rawPart);
        $this->assertNull($parsed, 'Oversized part must be discarded safely');
    }

    /** 23. Blocker 4: Oversized split part discarded, subsequent valid part ingested */
    public function test_23_oversized_part_discarded_and_subsequent_valid_part_processed(): void
    {
        $oversizedBody = str_repeat('Z', HikvisionAlertStreamClient::MAX_PART_SIZE + 200);
        $validBody = "{\"AccessControllerEvent\":{\"majorEventType\":5,\"subEventType\":1,\"cardNo\":\"CARD-998877\"}}";

        $part1 = "Content-Type: application/json\r\n\r\n" . $oversizedBody;
        $part2 = "Content-Type: application/json\r\n\r\n" . $validBody;

        $streamData = "--boundary\r\n{$part1}\r\n--boundary\r\n{$part2}\r\n--boundary\r\n";
        $buffer = $streamData;
        $boundary = 'boundary';
        $initialized = false;

        $parts = $this->streamClient->extractParts($buffer, $boundary, $initialized);

        // First part was oversized and dropped during extraction; second part remains
        $this->assertCount(1, $parts);
        $parsed = $this->streamClient->parsePart($parts[0]);
        $this->assertNotNull($parsed);
        $this->assertStringContainsString('CARD-998877', $parsed['body']);
    }

    /** 24. Blocker 3: Renewable lease manager lifecycle */
    public function test_24_renewable_lease_manager_lifecycle(): void
    {
        $tokenA = 'owner-token-A';
        $tokenB = 'owner-token-B';
        $doorId = $this->door->door_id;

        // 1. Owner A acquires
        $this->assertTrue($this->leaseManager->acquire($doorId, $tokenA, 2));
        $this->assertTrue($this->leaseManager->isOwner($doorId, $tokenA, 2));

        // 2. Owner B fails to acquire while A is alive
        $this->assertFalse($this->leaseManager->acquire($doorId, $tokenB, 2));

        // 3. Owner A renews lease
        $this->assertTrue($this->leaseManager->renew($doorId, $tokenA, 2));

        // 4. Wait for TTL to expire to simulate A dying
        sleep(3);

        // 5. Stale lock recovered by B
        $this->assertTrue($this->leaseManager->acquire($doorId, $tokenB, 2));
        $this->assertTrue($this->leaseManager->isOwner($doorId, $tokenB, 2));
        $this->assertFalse($this->leaseManager->isOwner($doorId, $tokenA, 2));

        // 6. Graceful release
        $this->assertTrue($this->leaseManager->release($doorId, $tokenB));
        $this->assertFalse($this->leaseManager->isOwner($doorId, $tokenB, 2));
    }

    /** 25. Blocker 2: HTTP 401/403 fails closed with AUTH_FAILED, exit code 2, no reconnect */
    public function test_25_command_http_401_fails_closed(): void
    {
        HikvisionAlertStreamClient::$streamHandler = function ($door, $onEvent, $shouldStop, $options) {
            return [
                'status' => 'error',
                'http_code' => 401,
                'last_error' => 'HTTP Error 401 Unauthorized',
                'events_received' => 0,
                'bytes_received' => 0,
                'start_time' => microtime(true),
                'end_time' => microtime(true),
                'runtime_seconds' => 0.05,
            ];
        };

        $this->artisan('door:stream-events', ['door_id' => $this->door->door_id])
            ->expectsOutputToContain('Autentikasi terminal gagal (HTTP 401)')
            ->assertExitCode(2);

        $health = Cache::get("hikvision-alertstream:health:{$this->door->door_id}");
        $this->assertIsArray($health);
        $this->assertEquals('AUTH_FAILED', $health['status']);
    }

    /** 26. Blocker 2: HTTP 404/405 fails closed with UNSUPPORTED, exit code 3, no reconnect */
    public function test_26_command_http_404_fails_closed(): void
    {
        HikvisionAlertStreamClient::$streamHandler = function ($door, $onEvent, $shouldStop, $options) {
            return [
                'status' => 'error',
                'http_code' => 404,
                'last_error' => 'HTTP Error 404 Not Found',
                'events_received' => 0,
                'bytes_received' => 0,
                'start_time' => microtime(true),
                'end_time' => microtime(true),
                'runtime_seconds' => 0.05,
            ];
        };

        $this->artisan('door:stream-events', ['door_id' => $this->door->door_id])
            ->expectsOutputToContain('Endpoint alertStream tidak didukung')
            ->assertExitCode(3);

        $health = Cache::get("hikvision-alertstream:health:{$this->door->door_id}");
        $this->assertIsArray($health);
        $this->assertEquals('UNSUPPORTED', $health['status']);
    }

    /** 27. Command option max-runtime stops idle stream */
    public function test_27_command_option_max_runtime_stops_idle_stream(): void
    {
        HikvisionAlertStreamClient::$streamHandler = function ($door, $onEvent, $shouldStop, $options) {
            return [
                'status' => 'stopped',
                'events_received' => 0,
                'bytes_received' => 0,
                'start_time' => microtime(true),
                'end_time' => microtime(true),
                'runtime_seconds' => 0.1,
                'last_error' => null,
            ];
        };

        $this->artisan('door:stream-events', [
            'door_id' => $this->door->door_id,
            '--max-runtime' => 0.1,
            '--no-reconnect' => true,
        ])->assertExitCode(0);
    }

    /** 28. Command concurrency lease prevents duplicate stream */
    public function test_28_command_concurrency_lease_prevents_duplicate_stream(): void
    {
        $this->leaseManager->acquire($this->door->door_id, 'existing-owner-token');

        $this->artisan('door:stream-events', ['door_id' => $this->door->door_id])
            ->expectsOutputToContain('Stream sudah aktif untuk pintu')
            ->assertExitCode(0);

        $this->leaseManager->release($this->door->door_id, 'existing-owner-token');
    }

    /** 29. Command health tracking states */
    public function test_29_command_health_tracking_states(): void
    {
        HikvisionAlertStreamClient::$streamHandler = function ($door, $onEvent, $shouldStop, $options) {
            if (isset($options['on_connected'])) {
                $options['on_connected']();
            }

            $onEvent([
                'card_no' => 'CARD-998877',
                'event_type' => 'STANDARD_TAP',
            ], $door);

            return [
                'status' => 'completed',
                'events_received' => 1,
                'bytes_received' => 256,
                'start_time' => microtime(true),
                'end_time' => microtime(true),
                'runtime_seconds' => 0.1,
                'last_error' => null,
            ];
        };

        $this->artisan('door:stream-events', [
            'door_id' => $this->door->door_id,
            '--once' => true,
        ])->assertExitCode(0);

        $health = Cache::get("hikvision-alertstream:health:{$this->door->door_id}");
        $this->assertIsArray($health);
        $this->assertEquals('STOPPED', $health['status']);
        $this->assertEquals(1, $health['events_received']);
    }

    /** 30. Command console privacy: no employee name or card in output */
    public function test_30_command_console_privacy_no_employee_name_or_card(): void
    {
        HikvisionAlertStreamClient::$streamHandler = function ($door, $onEvent, $shouldStop, $options) {
            $onEvent([
                'card_no' => 'CARD-998877',
                'event_type' => 'STANDARD_TAP',
            ], $door);

            return [
                'status' => 'completed',
                'events_received' => 1,
                'bytes_received' => 256,
                'start_time' => microtime(true),
                'end_time' => microtime(true),
                'runtime_seconds' => 0.1,
                'last_error' => null,
            ];
        };

        $this->artisan('door:stream-events', [
            'door_id' => $this->door->door_id,
            '--once' => true,
        ])
            ->doesntExpectOutputToContain('Siti Aminah')
            ->doesntExpectOutputToContain('CARD-998877')
            ->assertExitCode(0);
    }

    /** 31. Current event in LIVE_ONLY mode is ingested */
    public function test_31_current_event_in_live_only_ingested(): void
    {
        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => now()->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);

        $this->assertEquals('success', $result['status']);
        $this->assertFalse($result['duplicate']);
        $this->assertEquals(1, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 32. Event 30s old is ingested within freshness window */
    public function test_32_event_30s_old_ingested(): void
    {
        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => now()->subSeconds(30)->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', [
            'mode' => 'LIVE_ONLY',
            'max_event_age_seconds' => 120,
        ]);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 33. Event older than max age is skipped as historical backlog */
    public function test_33_event_older_than_max_age_skipped(): void
    {
        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => now()->subSeconds(200)->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', [
            'mode' => 'LIVE_ONLY',
            'max_event_age_seconds' => 120,
        ]);

        $this->assertEquals('skipped', $result['status']);
        $this->assertEquals('HISTORICAL_BACKLOG', $result['reason_code']);
        $this->assertEquals(0, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 34. Skipped backlog creates zero AccessLog rows */
    public function test_34_skipped_backlog_creates_zero_access_log(): void
    {
        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => '2026-07-24T10:45:27+07:00',
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);

        $this->assertEquals('skipped', $result['status']);
        $this->assertEquals(0, AccessLog::count());
    }

    /** 35. Skipped backlog dispatches zero AccessLogCreated events */
    public function test_35_skipped_backlog_dispatches_zero_access_log_created(): void
    {
        Event::fake([AccessLogCreated::class]);

        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => '2026-07-24T10:45:27+07:00',
        ];

        $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);

        Event::assertNotDispatched(AccessLogCreated::class);
    }

    /** 36. Skipped backlog creates zero AttendanceEvidence */
    public function test_36_skipped_backlog_creates_zero_attendance_evidence(): void
    {
        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => '2026-07-24T10:45:27+07:00',
        ];

        $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);

        $this->assertEquals(0, AttendanceEvidence::count());
    }

    /** 37. Skipped backlog invokes AttendanceProcessor zero times */
    public function test_37_skipped_backlog_invokes_attendance_processor_zero_times(): void
    {
        $processorSpy = $this->spy(AttendanceProcessor::class);

        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => '2026-07-24T10:45:27+07:00',
        ];

        $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);

        $processorSpy->shouldNotHaveReceived('processEvidence');
    }

    /** 38. Future timestamp within tolerance (+60s) accepted */
    public function test_38_future_timestamp_within_tolerance_accepted(): void
    {
        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => now()->addSeconds(60)->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', [
            'mode' => 'LIVE_ONLY',
            'max_future_skew_seconds' => 300,
        ]);

        $this->assertEquals('success', $result['status']);
        $this->assertEquals(1, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 39. Excessive future timestamp (+600s) rejected as invalid */
    public function test_39_excessive_future_timestamp_rejected(): void
    {
        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => now()->addSeconds(600)->toIso8601String(),
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', [
            'mode' => 'LIVE_ONLY',
            'max_future_skew_seconds' => 300,
        ]);

        $this->assertEquals('invalid', $result['status']);
        $this->assertEquals('INVALID_FUTURE_TIMESTAMP', $result['reason_code']);
        $this->assertEquals(0, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 40. Historical event with HISTORICAL_REPLAY / --allow-history can be ingested */
    public function test_40_historical_event_with_allow_history_can_be_ingested(): void
    {
        $payload = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => '2026-07-24T10:45:27+07:00',
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', [
            'mode' => 'HISTORICAL_REPLAY',
        ]);

        $this->assertEquals('success', $result['status']);
        $this->assertFalse($result['duplicate']);
        $this->assertEquals(1, AccessLog::where('door_id', $this->door->id)->count());
    }

    /** 41. Reconnect receiving same historical backlog is still skipped */
    public function test_41_reconnect_receiving_same_historical_backlog_still_skipped(): void
    {
        $backlogEvent = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => '2026-07-24T10:45:27+07:00',
            'serial_no' => 883,
        ];

        // First connection receives backlog
        $res1 = $this->ingestionService->ingest($backlogEvent, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);
        $this->assertEquals('skipped', $res1['status']);
        $this->assertEquals('HISTORICAL_BACKLOG', $res1['reason_code']);

        // Reconnect after brief pause receives same backlog
        $res2 = $this->ingestionService->ingest($backlogEvent, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);
        $this->assertEquals('skipped', $res2['status']);
        $this->assertEquals('HISTORICAL_BACKLOG', $res2['reason_code']);

        $this->assertEquals(0, AccessLog::count());
    }

    /** 42. Duplicate current event creates only one AccessLog */
    public function test_42_duplicate_current_event_creates_one_log(): void
    {
        $now = now()->toIso8601String();
        $event = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => $now,
            'serial_no' => 1001,
        ];

        $res1 = $this->ingestionService->ingest($event, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);
        $this->assertEquals('success', $res1['status']);
        $this->assertFalse($res1['duplicate']);

        $res2 = $this->ingestionService->ingest($event, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);
        $this->assertEquals('success', $res2['status']);
        $this->assertTrue($res2['duplicate']);

        $this->assertEquals(1, AccessLog::count());
    }

    /** 43. Two different current events create two AccessLog rows */
    public function test_43_two_different_current_events_create_two_logs(): void
    {
        $event1 = [
            'card_no' => 'CARD-998877',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => now()->toIso8601String(),
            'serial_no' => 1001,
        ];

        $event2 = [
            'card_no' => 'CARD-112233',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => now()->addSecond()->toIso8601String(),
            'serial_no' => 1002,
        ];

        $res1 = $this->ingestionService->ingest($event1, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);
        $res2 = $this->ingestionService->ingest($event2, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);

        $this->assertEquals('success', $res1['status']);
        $this->assertFalse($res1['duplicate']);
        $this->assertEquals('success', $res2['status']);
        $this->assertFalse($res2['duplicate']);

        $this->assertEquals(2, AccessLog::count());
    }

    /** 44. Counters correctly distinguish received, ingested, skipped, duplicates, and invalid */
    public function test_44_counters_correctly_distinguish_received_ingested_skipped(): void
    {
        HikvisionAlertStreamClient::$streamHandler = function ($door, $onEvent, $shouldStop, $options) {
            // 1. Current valid event -> Ingested
            $onEvent([
                'card_no' => 'CARD-998877',
                'event_type' => 'STANDARD_TAP',
                'timestamp' => now()->toIso8601String(),
                'serial_no' => 101,
            ], $door);

            // 2. Duplicate of event 1 -> Duplicate
            $onEvent([
                'card_no' => 'CARD-998877',
                'event_type' => 'STANDARD_TAP',
                'timestamp' => now()->toIso8601String(),
                'serial_no' => 101,
            ], $door);

            // 3. Historical backlog event 1 -> Skipped Backlog
            $onEvent([
                'card_no' => 'CARD-998877',
                'event_type' => 'STANDARD_TAP',
                'timestamp' => '2026-07-24T10:45:27+07:00',
                'serial_no' => 883,
            ], $door);

            // 4. Historical backlog event 2 -> Skipped Backlog
            $onEvent([
                'card_no' => 'CARD-112233',
                'event_type' => 'STANDARD_TAP',
                'timestamp' => '2026-07-24T10:54:19+07:00',
                'serial_no' => 897,
            ], $door);

            // 5. Excessive future event -> Invalid
            $onEvent([
                'card_no' => 'CARD-998877',
                'event_type' => 'STANDARD_TAP',
                'timestamp' => now()->addHours(5)->toIso8601String(),
                'serial_no' => 999,
            ], $door);

            return [
                'status' => 'completed',
                'events_received' => 5,
                'bytes_received' => 1024,
                'start_time' => microtime(true),
                'end_time' => microtime(true),
                'runtime_seconds' => 0.1,
                'last_error' => null,
            ];
        };

        $this->artisan('door:stream-events', [
            'door_id' => $this->door->door_id,
            '--once' => true,
        ])
            ->expectsOutputToContain('Statistik Ingestion: Received=5, Ingested=1, SkippedBacklog=2, Duplicates=1, Invalid=1')
            ->assertExitCode(0);

        $health = Cache::get("hikvision-alertstream:health:{$this->door->door_id}");
        $this->assertIsArray($health);
        $this->assertEquals(5, $health['events_received']);
        $this->assertEquals(1, $health['events_ingested']);
        $this->assertEquals(2, $health['events_skipped_backlog']);
        $this->assertEquals(1, $health['events_duplicates']);
        $this->assertEquals(1, $health['events_invalid']);
    }

    /** 45. No PII in backlog-skip logs and responses */
    public function test_45_no_pii_in_backlog_skip_logs(): void
    {
        $rawCard = 'CARD-SECRET-998877';
        $payload = [
            'card_no' => $rawCard,
            'user' => 'EMP-SECRET-001',
            'event_type' => 'STANDARD_TAP',
            'timestamp' => '2026-07-24T10:45:27+07:00',
        ];

        $result = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'LIVE_ONLY']);

        $this->assertEquals('skipped', $result['status']);
        $jsonResult = json_encode($result);
        $this->assertStringNotContainsString($rawCard, $jsonResult);
        $this->assertStringNotContainsString('EMP-SECRET-001', $jsonResult);
        $this->assertArrayNotHasKey('card_no', $result['data']);
        $this->assertArrayNotHasKey('nik', $result['data']);
    }

    /** 46. Existing webhook behavior unchanged with historical fixtures */
    public function test_46_existing_webhook_behavior_unchanged(): void
    {
        config([
            'services.hikvision.allowed_device_ips' => $this->door->device_ip,
            'services.hikvision.device_secret' => 'test-secret',
        ]);

        $xmlPayload = <<<XML
<EventNotificationAlert version="2.0" xmlns="http://www.hikvision.com/vapix/v1">
    <ipAddress>{$this->door->device_ip}</ipAddress>
    <dateTime>2026-09-08T15:10:00+07:00</dateTime>
    <AccessControllerEvent>
        <majorEventType>5</majorEventType>
        <subEventType>1</subEventType>
        <cardNo>CARD-998877</cardNo>
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
                'REMOTE_ADDR' => $this->door->device_ip,
                'HTTP_X_DEVICE_SECRET' => 'test-secret',
                'CONTENT_TYPE' => 'application/xml',
            ],
            $xmlPayload
        );

        $response->assertStatus(200);
        $this->assertEquals(1, AccessLog::where('door_id', $this->door->id)->count());
    }
}


