<?php

namespace Tests\Feature;

use App\Jobs\SyncDoorAccessJob;
use App\Models\ActivityLog;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncDoorAccessDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private Door $door;

    protected function setUp(): void
    {
        parent::setUp();
        $this->door = Door::create([
            'door_id' => 'DOOR-B',
            'door_name' => 'Door B',
            'location' => 'Gedung B',
            'device_ip' => '10.0.0.15',
            'isapi_username' => 'admin',
            'isapi_password' => 'device-secret',
        ]);
    }

    public function test_inactive_employee_is_blocked_before_any_isapi_call(): void
    {
        $assignment = $this->assignment('INACTIVE');
        $isapi = Mockery::mock(HikvisionIsapiService::class);
        $isapi->shouldNotReceive('pingDevice');
        $isapi->shouldNotReceive('provisionEmployeeAccess');

        (new SyncDoorAccessJob($assignment->id))->handle($isapi);

        $assignment->refresh();
        $this->assertSame(['failed', 0, null, 'INACTIVE'], [$assignment->sync_status, $assignment->sync_attempts, $assignment->failed_step, $assignment->last_sync_status_code]);
        $this->assertStringContainsString('not ACTIVE', $assignment->last_sync_error);
        $this->assertSame('INACTIVE', $assignment->employee->fresh()->employment_status);
        $this->assertSame(1, (new SyncDoorAccessJob($assignment->id))->tries);
    }

    public function test_active_employee_provisions_normally(): void
    {
        $assignment = $this->assignment('ACTIVE', 'CARD-SECRET-778899');
        $isapi = Mockery::mock(HikvisionIsapiService::class);
        $isapi->shouldReceive('pingDevice')->once()->andReturnTrue();
        $isapi->shouldReceive('provisionEmployeeAccess')->once()->andReturn(['status' => true]);

        (new SyncDoorAccessJob($assignment->id))->handle($isapi);

        $assignment->refresh();
        $this->assertSame(['synced', 1, null, null, null], [
            $assignment->sync_status,
            $assignment->sync_attempts,
            $assignment->last_sync_error,
            $assignment->failed_step,
            $assignment->last_sync_status_code,
        ]);
    }

    public function test_user_info_error_message_and_status_code_are_stored_separately(): void
    {
        $assignment = $this->assignment('ACTIVE');
        $isapi = Mockery::mock(HikvisionIsapiService::class);
        $isapi->shouldReceive('pingDevice')->once()->andReturnTrue();
        $isapi->shouldReceive('provisionEmployeeAccess')->once()->andReturn([
            'status' => false,
            'failed_step' => 'USER_INFO',
            'statusCode' => 400,
            'error' => "  Invalid\n employeeNo  ",
        ]);

        (new SyncDoorAccessJob($assignment->id))->handle($isapi);

        $assignment->refresh();
        $this->assertSame('USER_INFO', $assignment->failed_step);
        $this->assertSame('400', $assignment->last_sync_status_code);
        $this->assertSame('USER_INFO: Invalid employeeNo', $assignment->last_sync_error);
        $this->assertSame('Invalid employeeNo', ActivityLog::where('action', 'sync_door_failed')->value('error'));
    }

    /** @dataProvider failedSteps */
    public function test_failed_stage_is_persisted_and_logged_without_sensitive_data(string $failedStep): void
    {
        $assignment = $this->assignment('ACTIVE', 'CARD-SECRET-778899');
        $isapi = Mockery::mock(HikvisionIsapiService::class);
        $isapi->shouldReceive('pingDevice')->once()->andReturnTrue();
        $isapi->shouldReceive('provisionEmployeeAccess')->once()->andReturn([
            'status' => false,
            'failed_step' => $failedStep,
            'statusCode' => '0x60000001',
            'error' => 'password=device-secret Authorization: Bearer abc.def token=secret card_no=CARD-SECRET-778899 credential_secret=hidden ' . str_repeat('x', 600),
        ]);

        (new SyncDoorAccessJob($assignment->id))->handle($isapi);

        $assignment->refresh();
        $this->assertSame(['failed', $failedStep, '0x60000001'], [$assignment->sync_status, $assignment->failed_step, $assignment->last_sync_status_code]);
        $this->assertStringStartsWith("{$failedStep}: [REDACTED]", $assignment->last_sync_error);
        $this->assertLessThanOrEqual(500, mb_strlen($assignment->last_sync_error));

        $log = ActivityLog::where('action', 'sync_door_failed')->firstOrFail();
        $this->assertSame([$assignment->id, $assignment->employee_id, $assignment->door_id, $failedStep, '0x60000001'], [
            $log->assignment_id,
            $log->employee_id,
            $log->door_id,
            $log->failed_step,
            $log->status_code,
        ]);
        $this->assertLessThanOrEqual(500, mb_strlen($log->error));
        $serializedLog = json_encode($log->getAttributes());
        $this->assertStringNotContainsString('device-secret', $serializedLog);
        $this->assertStringNotContainsString('CARD-SECRET-778899', $serializedLog);
        $this->assertStringNotContainsString('password', $serializedLog);
        $this->assertStringNotContainsString('abc.def', $serializedLog);
        $this->assertStringNotContainsString('credential_secret', $serializedLog);
    }

    public static function failedSteps(): array
    {
        return [
            'user info' => ['USER_INFO'],
            'card sync' => ['CARD_SYNC'],
            'access right' => ['ACCESS_RIGHT'],
        ];
    }

    private function assignment(string $employmentStatus, ?string $cardNo = null): DoorAssignment
    {
        $employee = Employee::factory()->create([
            'employment_status' => $employmentStatus,
            'card_no' => $cardNo,
        ]);

        return DoorAssignment::create([
            'employee_id' => $employee->id,
            'door_id' => $this->door->id,
            'sync_status' => 'pending',
        ]);
    }
}
