<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\Door;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_user_inventory_returns_safe_metadata(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();
        Http::fake(['*/AccessControl/UserInfo/Search?format=json' => Http::response([
            'UserInfoSearch' => [
                'totalMatches' => 1,
                'UserInfo' => [[
                    'employeeNo' => 'DEVICE-001',
                    'name' => 'Operator Satu',
                    'Valid' => ['enable' => true],
                    'numOfCard' => 1,
                    'fingerPrint' => 'must-not-leak',
                ]],
            ],
        ])]);

        $result = app(HikvisionIsapiService::class)->fetchUsers($door);

        $this->assertTrue($result['status']);
        $this->assertSame(1, $result['total_device_matches']);
        $this->assertSame(1, $result['inspected_users']);
        $this->assertSame([[
            'employee_no' => 'DEVICE-001',
            'name' => 'Operator Satu',
            'status' => '1',
            'card_count' => 1,
        ]], $result['users']);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/Search?format=json'));
    }

    public function test_fetch_users_uses_hikvision_compatible_search_id(): void
    {
        Config::set('services.hikvision.use_mock', false);
        Http::fake(['*/AccessControl/UserInfo/Search?format=json' => Http::response([
            'UserInfoSearch' => ['totalMatches' => 0, 'numOfMatches' => 0, 'UserInfo' => []],
        ])]);

        app(HikvisionIsapiService::class)->fetchUsers($this->door());

        Http::assertSent(function ($request): bool {
            $searchId = $request->data()['UserInfoSearchCond']['searchID'];

            return str_ends_with($request->url(), '/AccessControl/UserInfo/Search?format=json')
                && strlen($searchId) === 32
                && ctype_xdigit($searchId)
                && !str_contains($searchId, '-');
        });
    }

    public function test_fetch_users_paginates_ten_at_a_time(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $condition = $request->data()['UserInfoSearchCond'];
            $requests[] = $condition;
            $position = $condition['searchResultPosition'];
            $count = $position === 20 ? 6 : 10;

            return Http::response(['UserInfoSearch' => [
                'totalMatches' => 26,
                'numOfMatches' => $count,
                'responseStatusStrg' => $position === 20 ? 'OK' : 'MORE',
                'UserInfo' => $this->users($position, $count),
            ]]);
        });

        $result = app(HikvisionIsapiService::class)->fetchUsers($this->door(), 100);

        $this->assertTrue($result['status']);
        $this->assertSame(26, $result['total_device_matches']);
        $this->assertSame(26, $result['inspected_users']);
        $this->assertSame([0, 10, 20], array_column($requests, 'searchResultPosition'));
        $this->assertSame([10, 10, 10], array_column($requests, 'maxResults'));
        $this->assertCount(1, array_unique(array_column($requests, 'searchID')));
    }

    public function test_fetch_users_stops_at_requested_limit(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $requests = [];
        Http::fake(function ($request) use (&$requests) {
            $condition = $request->data()['UserInfoSearchCond'];
            $requests[] = $condition;

            return Http::response(['UserInfoSearch' => [
                'totalMatches' => 96,
                'numOfMatches' => 10,
                'responseStatusStrg' => 'MORE',
                'UserInfo' => $this->users($condition['searchResultPosition'], 10),
            ]]);
        });

        $result = app(HikvisionIsapiService::class)->fetchUsers($this->door(), 15);

        $this->assertSame(96, $result['total_device_matches']);
        $this->assertSame(15, $result['inspected_users']);
        $this->assertCount(15, $result['users']);
        $this->assertSame([0, 10], array_column($requests, 'searchResultPosition'));
    }

    public function test_fetch_users_does_not_expose_sensitive_fields(): void
    {
        Config::set('services.hikvision.use_mock', false);
        Http::fake(['*/AccessControl/UserInfo/Search?format=json' => Http::response([
            'UserInfoSearch' => [
                'totalMatches' => 1,
                'numOfMatches' => 1,
                'UserInfo' => [[
                    'employeeNo' => 'DEVICE-001',
                    'name' => 'Safe Name',
                    'password' => 'secret-password',
                    'fingerPrint' => 'secret-biometric',
                    'token' => 'secret-token',
                    'raw_payload' => 'secret-payload',
                ]],
            ],
        ])]);

        $result = app(HikvisionIsapiService::class)->fetchUsers($this->door());
        $serialized = json_encode($result);

        $this->assertSame(['employee_no', 'name', 'status', 'card_count'], array_keys($result['users'][0]));
        foreach (['secret-password', 'secret-biometric', 'secret-token', 'secret-payload'] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
    }

    public function test_fetch_users_does_not_classify_bad_parameters_as_unsupported(): void
    {
        Config::set('services.hikvision.use_mock', false);
        Http::fake(['*/AccessControl/UserInfo/Search?format=json' => Http::response([
            'ResponseStatus' => ['subStatusCode' => 'badParameters', 'errorMsg' => '0x60000001'],
        ], 400)]);

        $result = app(HikvisionIsapiService::class)->fetchUsers($this->door());

        $this->assertFalse($result['status']);
        $this->assertFalse($result['unsupported']);
    }

    public function test_inventory_command_maps_external_id_without_database_mutation_or_sensitive_output(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();
        Employee::create([
            'employee_id' => 'EMP-LOCAL',
            'hikvision_employee_no' => 'DEVICE-001',
            'nik' => 'NIK-001',
            'name' => 'Operator Satu',
            'department' => 'Operations',
        ]);
        $before = [$door->fresh()->toArray(), Employee::query()->orderBy('id')->get()->toArray()];

        $service = $this->mock(HikvisionIsapiService::class);
        $service->shouldReceive('isMockMode')->once()->andReturnFalse();
        $service->shouldReceive('fetchUsers')->once()->andReturn([
            'status' => true,
            'total_device_matches' => 2,
            'inspected_users' => 2,
            'users' => [
                ['employee_no' => 'DEVICE-001', 'name' => 'Operator Satu', 'status' => '1', 'card_count' => 1],
                ['employee_no' => 'DEVICE-002', 'name' => 'Operator Dua', 'status' => '1', 'card_count' => null],
            ],
            'error' => null,
        ]);

        $this->artisan('door:inventory DOOR-B --real')
            ->expectsOutputToContain('MATCH: EMP-LOCAL')
            ->expectsOutputToContain('NEW CANDIDATE')
            ->expectsOutputToContain('DRY RUN ONLY')
            ->doesntExpectOutputToContain('fingerPrint')
            ->assertSuccessful();

        $this->assertSame($before, [$door->fresh()->toArray(), Employee::query()->orderBy('id')->get()->toArray()]);
    }

    public function test_inventory_reports_partial_results_and_excludes_conflicts_from_dummy_only(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();
        Employee::create([
            'employee_id' => 'EMP-A',
            'hikvision_employee_no' => 'SHARED-ID',
            'nik' => 'NIK-A',
            'name' => 'Employee A',
            'department' => 'Operations',
        ]);
        Employee::create([
            'employee_id' => 'SHARED-ID',
            'nik' => 'NIK-B',
            'name' => 'Employee B',
            'department' => 'Operations',
        ]);
        $before = Employee::query()->orderBy('id')->get()->toArray();

        $service = $this->mock(HikvisionIsapiService::class);
        $service->shouldReceive('isMockMode')->once()->andReturnFalse();
        $service->shouldReceive('fetchUsers')->once()->andReturn([
            'status' => true,
            'total_device_matches' => 5,
            'inspected_users' => 1,
            'users' => [[
                'employee_no' => 'SHARED-ID',
                'name' => 'Conflicting User',
                'status' => '1',
                'card_count' => null,
            ]],
            'error' => null,
        ]);

        $this->artisan('door:inventory DOOR-B --real --limit=1')
            ->expectsOutputToContain('CONFLICT')
            ->expectsOutputToContain('5')
            ->expectsOutputToContain('Inventory partial')
            ->doesntExpectOutputToContain('raw_payload')
            ->doesntExpectOutputToContain('fingerPrint')
            ->assertSuccessful();

        $this->assertSame($before, Employee::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('employees', 2);
        $this->assertDatabaseCount('door_assignments', 0);
    }

    public function test_unsupported_user_directory_falls_back_to_deduplicated_safe_events_without_mutation(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();
        foreach ([
            ['EMP-HIK', 'DEVICE-001', 'NIK-1'],
            ['LEGACY-ID', null, 'NIK-2'],
            ['EMP-NIK', null, 'LEGACY-NIK'],
            ['CONFLICT-A', 'SHARED', 'NIK-4'],
            ['SHARED', null, 'NIK-5'],
            ['DUMMY', null, 'NIK-6'],
        ] as [$employeeId, $hikvisionId, $nik]) {
            Employee::create(['employee_id' => $employeeId, 'hikvision_employee_no' => $hikvisionId, 'nik' => $nik, 'name' => $employeeId, 'department' => 'Operations']);
        }
        $before = [$door->fresh()->toArray(), Employee::query()->orderBy('id')->get()->toArray()];

        $service = $this->mock(HikvisionIsapiService::class);
        $service->shouldReceive('isMockMode')->once()->andReturnFalse();
        $service->shouldReceive('fetchUsers')->once()->withArgs(fn (Door $givenDoor, int $limit) => $givenDoor->is($door) && $limit === 5)->andReturn([
            'status' => false, 'unsupported' => true, 'total_device_matches' => 0, 'inspected_users' => 0, 'users' => [], 'error' => 'User directory unsupported.',
        ]);
        $service->shouldReceive('fetchEvents')->once()->withArgs(fn (int $limit, Door $givenDoor) => $limit === 5 && $givenDoor->is($door))->andReturn([
            'status' => true,
            'total' => 5,
            'total_device_matches' => 9,
            'events' => [
                $this->event('DEVICE-001', 'Primary', 'secret-card-1'),
                $this->event('DEVICE-001', 'Duplicate', 'secret-card-2'),
                $this->event('LEGACY-ID', 'Legacy employee ID', 'secret-card-3'),
                $this->event('LEGACY-NIK', 'Legacy NIK', 'secret-card-4'),
                $this->event('SHARED', 'Conflict', 'secret-card-5'),
                $this->event('', 'Unknown Name', 'secret-card-6', ['fingerPrint' => 'biometric-secret', 'raw_payload' => 'raw-secret', 'token' => 'token-secret']),
            ],
            'error' => null,
        ]);

        $this->artisan('door:inventory DOOR-B --real --limit=5')
            ->expectsOutputToContain('USER DIRECTORY: UNSUPPORTED')
            ->expectsOutputToContain('MODE: EVENT-DERIVED INVENTORY')
            ->expectsOutputToContain('NOT a full device user directory')
            ->expectsOutputToContain('MATCH: EMP-HIK')
            ->expectsOutputToContain('MATCH: LEGACY-ID')
            ->expectsOutputToContain('MATCH: EMP-NIK')
            ->expectsOutputToContain('CONFLICT')
            ->expectsOutputToContain('UNKNOWN EVENT IDENTITY')
            ->expectsOutputToContain('Event query partial/limited')
            ->doesntExpectOutputToContain('secret-card')
            ->doesntExpectOutputToContain('biometric-secret')
            ->doesntExpectOutputToContain('raw-secret')
            ->doesntExpectOutputToContain('token-secret')
            ->assertSuccessful();

        $this->assertSame($before, [$door->fresh()->toArray(), Employee::query()->orderBy('id')->get()->toArray()]);
        $this->assertDatabaseCount('access_logs', 0);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('attendance_evidences', 0);
    }

    public function test_service_detects_hikvision_not_support_without_invoking_write_endpoint(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();
        Http::fake(['*' => Http::response([
            'ResponseStatus' => ['subStatusCode' => 'notSupport', 'errorCode' => '0x40000001', 'errorMsg' => 'unsupported'],
        ], 400)]);

        $result = app(HikvisionIsapiService::class)->fetchUsers($door);

        $this->assertFalse($result['status']);
        $this->assertTrue($result['unsupported']);
        $this->assertSame('User directory unsupported.', $result['error']);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/AccessControl/UserInfo/Search?format=json')
            && !str_contains($request->url(), 'SetUp')
            && !str_contains($request->url(), 'Record')
            && !str_contains($request->url(), 'RemoteControl'));
    }

    public function test_fetch_users_probes_legacy_compatibility_once(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/AccessControl/UserInfo/Search?format=json')) {
                return Http::response(['errorCode' => '0x60000001'], 400);
            }

            if (str_ends_with($request->url(), '/AccessControl/UserInfo/Search')) {
                return Http::response([
                    'UserInfoSearch' => [
                        'totalMatches' => 1,
                        'UserInfo' => [[
                            'employeeNo' => 'DEVICE-001',
                            'name' => 'Compatibility User',
                            'Valid' => ['enable' => true],
                            'numOfCard' => 0,
                        ]],
                    ],
                ], 200);
            }

            return Http::response([], 500);
        });

        $result = app(HikvisionIsapiService::class)->fetchUsers($door);

        $this->assertTrue($result['status']);
        $this->assertSame(1, $result['total_device_matches']);
        $this->assertSame('DEVICE-001', $result['users'][0]['employee_no']);
        Http::assertSentCount(2);
    }

    public function test_fetch_users_marks_not_support_after_compatibility_probe(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/AccessControl/UserInfo/Search?format=json')) {
                return Http::response(['errorCode' => '0x60000001'], 400);
            }

            return Http::response([
                'ResponseStatus' => [
                    'subStatusCode' => 'notSupport',
                    'errorCode' => '0x40000001',
                ],
            ], 400);
        });

        $result = app(HikvisionIsapiService::class)->fetchUsers($door);

        $this->assertFalse($result['status']);
        $this->assertTrue($result['unsupported']);
        $this->assertSame('User directory unsupported.', $result['error']);
        Http::assertSentCount(2);
    }

    public function test_fetch_users_does_not_globally_classify_0x60000001_as_unsupported(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();

        Http::fake(function ($request) {
            if (str_ends_with($request->url(), '/AccessControl/UserInfo/Search?format=json')) {
                return Http::response(['errorCode' => '0x60000001'], 400);
            }

            return Http::response([
                'ResponseStatus' => [
                    'statusString' => 'Device Busy',
                    'errorMsg' => 'Compatibility probe failed.',
                ],
            ], 503);
        });

        $result = app(HikvisionIsapiService::class)->fetchUsers($door);

        $this->assertFalse($result['status']);
        $this->assertFalse($result['unsupported']);
        $this->assertStringContainsString('Compatibility probe failed.', $result['error']);
        Http::assertSentCount(2);
    }

    public function test_accesslog_fallback_is_deduplicated_scoped_safe_and_read_only(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();
        $otherDoor = Door::create(['door_id' => 'DOOR-'.uniqid(), 'door_name' => 'Door A', 'location' => 'Gedung A', 'device_ip' => '192.168.90.11']);
        $employee = Employee::create(['employee_id' => 'EMP-LOCAL', 'hikvision_employee_no' => 'DEVICE-001', 'nik' => 'NIK-1', 'name' => 'Known Employee', 'department' => 'Operations']);
        $this->accessLog($door, 'LOG-1', $employee, 'DEVICE-001', now(), 'Card');
        $this->accessLog($door, 'LOG-2', $employee, 'DEVICE-001', now()->subMinute(), 'Fingerprint');
        $this->accessLog($door, 'LOG-3', null, null, now()->subMinutes(2), 'Fingerprint');
        $this->accessLog($otherDoor, 'LOG-OTHER', null, 'OTHER-SECRET-ID', now()->addMinute(), 'Card');
        $before = AccessLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();

        $service = $this->mock(HikvisionIsapiService::class);
        $service->shouldReceive('isMockMode')->once()->andReturnFalse();
        $service->shouldReceive('fetchUsers')->once()->andReturn(['status' => false, 'unsupported' => true, 'error' => 'User directory unsupported.']);
        $service->shouldReceive('fetchEvents')->once()->andReturn(['status' => false, 'unsupported' => true, 'error' => 'Device event search unsupported.']);

        $this->artisan('door:inventory DOOR-B --real --limit=3')
            ->expectsOutputToContain('USER DIRECTORY: UNSUPPORTED')
            ->expectsOutputToContain('DEVICE EVENT SEARCH: UNSUPPORTED')
            ->expectsOutputToContain('MODE: ACCESSLOG-DERIVED INVENTORY')
            ->expectsOutputToContain('NOT a full device user directory')
            ->expectsOutputToContain('MATCH: EMP-LOCAL')
            ->expectsOutputToContain('UNKNOWN EVENT IDENTITY')
            ->doesntExpectOutputToContain('OTHER-SECRET-ID')
            ->doesntExpectOutputToContain('CARD-SECRET')
            ->assertSuccessful();

        $this->assertSame($before, AccessLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('access_logs', 4);
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_opaque_legacy_event_probe_unsupported_uses_accesslog_fallback(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $door = $this->door();
        $employee = Employee::create(['employee_id' => 'EMP-LOCAL', 'hikvision_employee_no' => 'DEVICE-001', 'nik' => 'NIK-1', 'name' => 'Known Employee', 'department' => 'Operations']);
        $this->accessLog($door, 'LOG-1', $employee, 'DEVICE-001', now(), 'Card');
        $before = AccessLog::query()->orderBy('id')->get()->map->getRawOriginal()->all();

        Http::fake([
            '*/AccessControl/UserInfo/Search?format=json' => Http::response([
                'ResponseStatus' => ['subStatusCode' => 'notSupport', 'errorCode' => '0x40000001'],
            ], 400),
            '*/AccessControl/AcsEvent?format=json' => Http::response([
                'ResponseStatus' => ['subStatusCode' => 'notSupport', 'errorCode' => '0x40000001'],
            ], 400),
            '*/AccessControl/AcsEvent' => Http::response(['errorCode' => '0x60000001'], 400),
        ]);

        $this->artisan('door:inventory DOOR-B --real --limit=20')
            ->expectsOutputToContain('USER DIRECTORY: UNSUPPORTED')
            ->expectsOutputToContain('DEVICE EVENT SEARCH: UNSUPPORTED')
            ->expectsOutputToContain('MODE: ACCESSLOG-DERIVED INVENTORY')
            ->expectsOutputToContain('MATCH: EMP-LOCAL')
            ->assertSuccessful();

        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_ends_with($request->url(), '/AccessControl/AcsEvent?format=json'));
        $this->assertSame($before, AccessLog::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertDatabaseCount('access_logs', 1);
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_accesslog_fallback_handles_empty_selected_door_gracefully(): void
    {
        Config::set('services.hikvision.use_mock', false);
        $this->door();
        $service = $this->mock(HikvisionIsapiService::class);
        $service->shouldReceive('isMockMode')->once()->andReturnFalse();
        $service->shouldReceive('fetchUsers')->once()->andReturn(['status' => false, 'unsupported' => true, 'error' => 'unsupported']);
        $service->shouldReceive('fetchEvents')->once()->andReturn(['status' => false, 'unsupported' => true, 'error' => 'unsupported']);

        $this->artisan('door:inventory DOOR-B --real --limit=10')
            ->expectsOutputToContain('MODE: ACCESSLOG-DERIVED INVENTORY')
            ->expectsOutputToContain('DRY RUN ONLY')
            ->assertSuccessful();
    }

    private function accessLog(Door $door, string $logId, ?Employee $employee, ?string $nik, $timestamp, string $method): void
    {
        AccessLog::create([
            'log_id' => $logId,
            'door_id' => $door->id,
            'employee_id' => $employee?->id,
            'nik' => $nik,
            'verify_method' => $method,
            'access_status' => 'Granted',
            'timestamp' => $timestamp,
            'reason' => 'CARD-SECRET',
        ]);
    }

    private function event(string $employeeNo, string $name, string $cardNo, array $extra = []): array
    {
        return array_merge([
            'employee_no' => $employeeNo,
            'name' => $name,
            'verify_method' => 'Fingerprint',
            'access_status' => 'Granted',
            'time' => '2026-09-11T08:00:00+07:00',
            'door_no' => 1,
            'door_name' => 'Door B',
            'card_no' => $cardNo,
        ], $extra);
    }

    private function users(int $position, int $count): array
    {
        return array_map(static fn (int $index): array => [
            'employeeNo' => sprintf('DEVICE-%03d', $index + 1),
            'name' => "User {$index}",
            'Valid' => ['enable' => true],
            'numOfCard' => 1,
        ], range($position, $position + $count - 1));
    }

    private function door(): Door
    {
        return Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Door B',
            'location' => 'Gedung B',
            'device_ip' => '192.168.90.15',
            'device_model' => 'DS-K1T804AMF',
        ]);
    }
}
