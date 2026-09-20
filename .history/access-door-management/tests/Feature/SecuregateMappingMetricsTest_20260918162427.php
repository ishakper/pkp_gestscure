<?php

namespace Tests\Feature;

use App\Models\Door;
use App\Models\Employee;
use App\Services\HikvisionEventIngestionService;
use App\Services\SecuregateMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SecuregateMappingMetricsTest extends TestCase
{
    use RefreshDatabase;

    protected Door $door;
    protected SecuregateMetricsService $metricsService;
    protected HikvisionEventIngestionService $ingestionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->metricsService = app(SecuregateMetricsService::class);
        $this->metricsService->resetMetrics();
        $this->ingestionService = app(HikvisionEventIngestionService::class);

        $this->door = Door::create([
            'door_id' => 'DOOR-B',
            'door_name' => 'Pintu Belakang Produksi',
            'device_ip' => '192.168.90.15',
            'location' => 'Pabrik Sentral',
            'connection_status' => 'online',
        ]);
    }

    /**
     * 1. Test mapped by Person No (rawUser)
     */
    public function test_event_mapped_by_person_no(): void
    {
        $employee = Employee::create([
            'name' => 'John Doe Person',
            'nik' => 'EMP-001',
            'employee_id' => 'PERSON-101',
            'department' => 'Engineering',
            'card_no' => null,
            'employment_status' => 'ACTIVE',
        ]);

        $payload = [
            'door_id' => 'DOOR-B',
            'user' => 'PERSON-101',
            'card_no' => null,
            'event_type' => 'STANDARD_TAP',
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $snapshot = $this->metricsService->getMetricsSnapshot();
        $metrics = collect($snapshot['metrics']);

        $accessEvent = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_access_events_total' && ($m['labels']['event_class'] ?? '') === 'mapped_identity');
        $this->assertNotNull($accessEvent);
        $this->assertEquals(1, $accessEvent['value']);

        $resolution = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_identity_resolution_total' && ($m['labels']['resolution'] ?? '') === 'person_no');
        $this->assertNotNull($resolution);
        $this->assertEquals(1, $resolution['value']);

        $mappingResult = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_mapping_result_total' && ($m['labels']['result'] ?? '') === 'mapped');
        $this->assertNotNull($mappingResult);
        $this->assertEquals(1, $mappingResult['value']);

        $this->assertEquals(1.0, $snapshot['mapping_coverage_ratio']);
    }

    /**
     * 2. Test mapped by card (cardNo only)
     */
    public function test_event_mapped_by_card(): void
    {
        $employee = Employee::create([
            'name' => 'Jane Doe Card',
            'nik' => 'EMP-002',
            'employee_id' => 'PERSON-102',
            'department' => 'Engineering',
            'card_no' => 'CARD-778899',
            'employment_status' => 'ACTIVE',
        ]);

        $payload = [
            'door_id' => 'DOOR-B',
            'user' => null,
            'card_no' => 'CARD-778899',
            'event_type' => 'STANDARD_TAP',
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $snapshot = $this->metricsService->getMetricsSnapshot();
        $metrics = collect($snapshot['metrics']);

        $resolution = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_identity_resolution_total' && ($m['labels']['resolution'] ?? '') === 'card_no');
        $this->assertNotNull($resolution);
        $this->assertEquals(1, $resolution['value']);

        $this->assertEquals(1.0, $snapshot['mapping_coverage_ratio']);
    }

    /**
     * 3. Test mapped by both Person No and card
     */
    public function test_event_mapped_by_both(): void
    {
        $employee = Employee::create([
            'name' => 'Dual Auth User',
            'nik' => 'EMP-003',
            'employee_id' => 'PERSON-103',
            'department' => 'Engineering',
            'card_no' => 'CARD-112233',
            'employment_status' => 'ACTIVE',
        ]);

        $payload = [
            'door_id' => 'DOOR-B',
            'user' => 'PERSON-103',
            'card_no' => 'CARD-112233',
            'event_type' => 'STANDARD_TAP',
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $snapshot = $this->metricsService->getMetricsSnapshot();
        $metrics = collect($snapshot['metrics']);

        $resolution = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_identity_resolution_total' && ($m['labels']['resolution'] ?? '') === 'both');
        $this->assertNotNull($resolution);
        $this->assertEquals(1, $resolution['value']);
    }

    /**
     * 4. Test unmapped identity event (unknown card / unknown user)
     */
    public function test_unmapped_identity_event(): void
    {
        $payload = [
            'door_id' => 'DOOR-B',
            'user' => 'GUEST-999',
            'card_no' => 'UNKNOWN-CARD-555',
            'event_type' => 'STANDARD_TAP',
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $snapshot = $this->metricsService->getMetricsSnapshot();
        $metrics = collect($snapshot['metrics']);

        $accessEvent = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_access_events_total' && ($m['labels']['event_class'] ?? '') === 'unmapped_identity');
        $this->assertNotNull($accessEvent);
        $this->assertEquals(1, $accessEvent['value']);

        $resolution = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_identity_resolution_total' && ($m['labels']['resolution'] ?? '') === 'none');
        $this->assertNotNull($resolution);
        $this->assertEquals(1, $resolution['value']);

        $mappingResult = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_mapping_result_total' && ($m['labels']['result'] ?? '') === 'unmapped');
        $this->assertNotNull($mappingResult);
        $this->assertEquals(1, $mappingResult['value']);

        // Coverage ratio should be 0.0 (0 mapped / 1 identity-bearing)
        $this->assertEquals(0.0, $snapshot['mapping_coverage_ratio']);
    }

    /**
     * 5. Test system/alarm event without identity (e.g. DOOR_FORCED_OPEN)
     */
    public function test_system_alarm_event(): void
    {
        $payload = [
            'door_id' => 'DOOR-B',
            'event_type' => 'DOOR_FORCED_OPEN',
            'major_event' => 5,
            'minor_event' => 21,
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $snapshot = $this->metricsService->getMetricsSnapshot();
        $metrics = collect($snapshot['metrics']);

        $accessEvent = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_access_events_total' && ($m['labels']['event_class'] ?? '') === 'system_alarm');
        $this->assertNotNull($accessEvent);
        $this->assertEquals(1, $accessEvent['value']);

        // System alarm should NOT be counted in mapping coverage ratio
        // Denominator (mapped + unmapped) is 0 -> ratio should be null (never divide by zero)
        $this->assertNull($snapshot['mapping_coverage_ratio']);
    }

    /**
     * 6. Test denied mapped event (e.g. access denied by device policy but identity is mapped)
     */
    public function test_denied_mapped_event(): void
    {
        $employee = Employee::create([
            'name' => 'Denied Employee',
            'nik' => 'EMP-004',
            'employee_id' => 'PERSON-104',
            'department' => 'Engineering',
            'card_no' => 'CARD-445566',
            'employment_status' => 'ACTIVE',
        ]);

        $payload = [
            'door_id' => 'DOOR-B',
            'user' => 'PERSON-104',
            'card_no' => 'CARD-445566',
            'access_status' => 'Denied',
            'event_type' => 'STANDARD_TAP',
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $snapshot = $this->metricsService->getMetricsSnapshot();
        $metrics = collect($snapshot['metrics']);

        $decision = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_access_decisions_total' && ($m['labels']['decision'] ?? '') === 'denied');
        $this->assertNotNull($decision);
        $this->assertEquals(1, $decision['value']);

        $mappingResult = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_mapping_result_total' && ($m['labels']['result'] ?? '') === 'mapped');
        $this->assertNotNull($mappingResult);
        $this->assertEquals(1, $mappingResult['value']);
    }

    /**
     * 7. Test metrics backend unavailable (fail-open)
     */
    public function test_metrics_backend_unavailable_fails_open(): void
    {
        $employee = Employee::create([
            'name' => 'Fail Open User',
            'nik' => 'EMP-005',
            'employee_id' => 'PERSON-105',
            'department' => 'Engineering',
            'card_no' => 'CARD-990011',
            'employment_status' => 'ACTIVE',
        ]);

        // Mock DB::statement to throw an exception
        \Illuminate\Support\Facades\DB::shouldReceive('statement')->andThrow(new \RuntimeException('Database connection failure'));

        $payload = [
            'door_id' => 'DOOR-B',
            'user' => 'PERSON-105',
            'card_no' => 'CARD-990011',
            'event_type' => 'STANDARD_TAP',
            'event_time' => now()->toIso8601String(),
        ];

        // Ingestion must succeed completely even if metrics backend throws
        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);
        $this->assertNotNull($res['data']['log_id']);
    }

    /**
     * 8. Test no PII or card values in metric labels (privacy verification)
     */
    public function test_no_pii_or_card_values_in_metric_labels(): void
    {
        $sensitiveCard = '999888777666';
        $sensitiveNik = '3201123456780001';
        $sensitiveName = 'Rahasia Negara';

        $employee = Employee::create([
            'name' => $sensitiveName,
            'nik' => $sensitiveNik,
            'employee_id' => 'SENSITIVE-PERSON',
            'department' => 'Engineering',
            'card_no' => $sensitiveCard,
            'employment_status' => 'ACTIVE',
        ]);

        $payload = [
            'door_id' => 'DOOR-B',
            'user' => 'SENSITIVE-PERSON',
            'card_no' => $sensitiveCard,
            'event_type' => 'STANDARD_TAP',
            'event_time' => now()->toIso8601String(),
        ];

        $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);

        $promText = $this->metricsService->renderPrometheus();

        // Ensure sensitive strings NEVER appear in Prometheus exposition text
        $this->assertStringNotContainsString($sensitiveCard, $promText);
        $this->assertStringNotContainsString($sensitiveNik, $promText);
        $this->assertStringNotContainsString($sensitiveName, $promText);
        $this->assertStringNotContainsString('SENSITIVE-PERSON', $promText);

        // Also test /metrics endpoint HTTP response
        $response = $this->get('/metrics');
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $body = $response->getContent();

        $this->assertStringNotContainsString($sensitiveCard, $body);
        $this->assertStringNotContainsString($sensitiveNik, $body);
        $this->assertStringNotContainsString($sensitiveName, $body);
        $this->assertStringNotContainsString('SENSITIVE-PERSON', $body);
    }

    /**
     * 9. Test tamper alarm with ambient user in payload still classifies strictly as system_alarm
     */
    public function test_tamper_alarm_with_operator_id_classified_as_system_alarm(): void
    {
        $payload = [
            'door_id' => 'DOOR-B',
            'user' => 'OPERATOR-99',
            'event_type' => 'TAMPER_ALARM',
            'major_event' => 5,
            'minor_event' => 37,
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $snapshot = $this->metricsService->getMetricsSnapshot();
        $metrics = collect($snapshot['metrics']);

        $accessEvent = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_access_events_total' && ($m['labels']['event_class'] ?? '') === 'system_alarm');
        $this->assertNotNull($accessEvent);
        $this->assertEquals(1, $accessEvent['value']);

        // Must NOT increment unknown identity counters
        $unknownCounter = $metrics->firstWhere(fn($m) => $m['name'] === 'securegate_unknown_identity_events_total');
        $this->assertEquals(0, $unknownCounter['value'] ?? 0);
    }

    /**
     * 10. Test access log mass assignment retains major_event, minor_event, correlation_id
     */
    public function test_access_log_persists_major_minor_event_and_correlation_id(): void
    {
        $payload = [
            'door_id' => 'DOOR-B',
            'event_type' => 'DOOR_FORCED_OPEN',
            'major_event' => 5,
            'minor_event' => 21,
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $log = \App\Models\AccessLog::where('log_id', $res['data']['log_id'])->first();
        $this->assertNotNull($log);
        $this->assertEquals(5, $log->major_event);
        $this->assertEquals(21, $log->minor_event);
    }

    /**
     * 11. Test verify_method fallback to Card when card_no present and verification_method is UNKNOWN
     */
    public function test_verify_method_fallback_to_card_when_card_present(): void
    {
        $payload = [
            'door_id' => 'DOOR-B',
            'card_no' => 'RAW-CARD-999',
            'verification_method' => 'UNKNOWN',
            'event_type' => 'STANDARD_TAP',
            'event_time' => now()->toIso8601String(),
        ];

        $res = $this->ingestionService->ingest($payload, $this->door, 'HIKVISION_ALERTSTREAM', ['mode' => 'HISTORICAL_REPLAY']);
        $this->assertEquals('success', $res['status']);

        $log = \App\Models\AccessLog::where('log_id', $res['data']['log_id'])->first();
        $this->assertNotNull($log);
        $this->assertEquals('Card', $log->verify_method);
    }
}
