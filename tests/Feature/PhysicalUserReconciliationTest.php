<?php

namespace Tests\Feature;

use App\Models\Door;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use App\Services\PhysicalUserReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PhysicalUserReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.hikvision.use_mock' => false]);
    }

    public function test_reconciliation_dry_run_identifies_matching_and_pending_users_with_zero_card_leak(): void
    {
        $door = Door::create([
            'door_id' => 'DOOR-B',
            'name' => 'Lab Pintu B',
            'location' => 'Building B 1st Floor',
            'ip_address' => '192.168.90.15',
            'port' => 80,
            'status' => 'online',
        ]);

        Employee::create([
            'employee_id' => '1001',
            'name' => 'Existing Employee 1001',
            'nik' => 'ID-001001',
            'department' => 'Engineering',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $fakeCardNo1 = '87654321';
        $fakeCardNo2 = '11223344';

        Http::fake([
            'http://192.168.90.15/ISAPI/AccessControl/UserInfo/Search*' => Http::response([
                'UserInfoSearch' => [
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 2,
                    'totalMatches' => 2,
                    'UserInfo' => [
                        [
                            'employeeNo' => '1001',
                            'name' => 'Existing Employee 1001',
                            'enable' => true,
                        ],
                        [
                            'employeeNo' => '1002',
                            'name' => 'Physical Worker 1002',
                            'enable' => true,
                        ],
                    ],
                ],
            ], 200),
            'http://192.168.90.15/ISAPI/AccessControl/CardInfo/Search*' => Http::response([
                'CardInfoSearch' => [
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 1,
                    'totalMatches' => 1,
                    'CardInfo' => [
                        ['employeeNo' => '1001', 'cardNo' => $fakeCardNo1],
                    ],
                ],
            ], 200),
        ]);

        $service = app(PhysicalUserReconciliationService::class);
        $result = $service->reconcile($door, false, 50);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['apply']);
        $this->assertSame(2, $result['stats']['device_users']);
        $this->assertSame(2, $result['stats']['unique_device_employee_no']);
        $this->assertSame(1, $result['stats']['matched_existing']);
        $this->assertSame(1, $result['stats']['pending_creation']);
        $this->assertSame(0, $result['stats']['created']);
        $this->assertSame(1, $result['stats']['card_registered']);
        $this->assertSame(1, $result['stats']['no_card']);
        $this->assertSame(0, $result['stats']['duplicate_employee_no']);

        // Assert card number strings, fragments, or masks NEVER leak into result
        $serialized = json_encode($result);
        $this->assertStringNotContainsString($fakeCardNo1, $serialized);
        $this->assertStringNotContainsString('4321', $serialized);
        $this->assertStringNotContainsString('card_no', $serialized);
        $this->assertStringNotContainsString('cardNo', $serialized);
        $this->assertStringNotContainsString('masked_card', $serialized);

        // Dry run must not touch database
        $this->assertSame(1, Employee::count());
    }

    public function test_reconciliation_apply_is_idempotent_and_classifies_missing_business_data(): void
    {
        $door = Door::create([
            'door_id' => 'DOOR-B',
            'name' => 'Lab Pintu B',
            'location' => 'Building B 1st Floor',
            'ip_address' => '192.168.90.15',
            'port' => 80,
            'status' => 'online',
        ]);

        $fakeCardNo = '99887766';

        Http::fake([
            'http://192.168.90.15/ISAPI/AccessControl/UserInfo/Search*' => Http::response([
                'UserInfoSearch' => [
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 1,
                    'totalMatches' => 1,
                    'UserInfo' => [
                        [
                            'employeeNo' => '1050',
                            'name' => 'Physical Plant Operator',
                            'enable' => true,
                        ],
                    ],
                ],
            ], 200),
            'http://192.168.90.15/ISAPI/AccessControl/CardInfo/Search*' => Http::response([
                'CardInfoSearch' => [
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 1,
                    'totalMatches' => 1,
                    'CardInfo' => [
                        ['employeeNo' => '1050', 'cardNo' => $fakeCardNo],
                    ],
                ],
            ], 200),
        ]);

        $service = app(PhysicalUserReconciliationService::class);

        // First apply: creates missing physical identity
        $firstResult = $service->reconcile($door, true, 50);
        $this->assertTrue($firstResult['success']);
        $this->assertSame(1, $firstResult['stats']['created']);
        $this->assertSame(0, $firstResult['stats']['matched_existing']);

        $created = Employee::where('employee_id', '1050')->first();
        $this->assertNotNull($created);
        $this->assertSame('Physical Plant Operator', $created->name);
        $this->assertSame('NEEDS_BUSINESS_DATA', $created->department);
        $this->assertSame('NEEDS_BUSINESS_DATA', $created->employment_status);
        $this->assertNull($created->card_no);

        // Second apply: idempotency check (must create 0, matched 1)
        $secondResult = $service->reconcile($door, true, 50);
        $this->assertTrue($secondResult['success']);
        $this->assertSame(0, $secondResult['stats']['created']);
        $this->assertSame(1, $secondResult['stats']['matched_existing']);
        $this->assertSame(0, $secondResult['stats']['conflict']);
        $this->assertSame(1, Employee::where('employee_id', '1050')->count());
    }

    public function test_command_runs_without_card_exposure(): void
    {
        $door = Door::create([
            'door_id' => 'DOOR-B',
            'name' => 'Lab Pintu B',
            'location' => 'Building B 1st Floor',
            'ip_address' => '192.168.90.15',
            'port' => 80,
            'status' => 'online',
        ]);

        $fakeCard = '55443322';

        Http::fake([
            'http://192.168.90.15/ISAPI/AccessControl/UserInfo/Search*' => Http::response([
                'UserInfoSearch' => [
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 1,
                    'totalMatches' => 1,
                    'UserInfo' => [
                        ['employeeNo' => '2001', 'name' => 'Operator A', 'enable' => true],
                    ],
                ],
            ], 200),
            'http://192.168.90.15/ISAPI/AccessControl/CardInfo/Search*' => Http::response([
                'CardInfoSearch' => [
                    'responseStatusStrg' => 'OK',
                    'numOfMatches' => 1,
                    'totalMatches' => 1,
                    'CardInfo' => [
                        ['employeeNo' => '2001', 'cardNo' => $fakeCard],
                    ],
                ],
            ], 200),
        ]);

        $this->artisan('door:reconcile-users DOOR-B')
            ->assertExitCode(0)
            ->expectsOutputToContain('Starting reconciliation for Lab Pintu B')
            ->expectsOutputToContain('DRY-RUN')
            ->expectsOutputToContain('DEVICE_USERS: 1')
            ->expectsOutputToContain('CARD_REGISTERED: 1')
            ->doesntExpectOutput($fakeCard)
            ->doesntExpectOutput('3322');

        $this->assertSame(0, Employee::where('employee_id', '2001')->count());

        $this->artisan('door:reconcile-users DOOR-B --apply')
            ->assertExitCode(0)
            ->expectsOutputToContain('CREATED: 1');

        $this->assertSame(1, Employee::where('employee_id', '2001')->count());
    }
}
