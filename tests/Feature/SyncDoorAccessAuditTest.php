<?php

namespace Tests\Feature;

use App\Jobs\SyncDoorAccessJob;
use App\Models\DeviceAuditLog;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncDoorAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_creates_audit_row_and_is_idempotently_skipped(): void
    {
        [$assignment] = $this->assignment();
        $service = Mockery::mock(HikvisionIsapiService::class);
        $service->shouldReceive('pingDevice')->once()->andReturnTrue();
        $service->shouldReceive('provisionEmployeeAccess')->once()->andReturn(['status' => true]);

        $job = new SyncDoorAccessJob($assignment->id);
        $job->handle($service);
        (new SyncDoorAccessJob($assignment->id))->handle($service);

        $this->assertDatabaseHas('device_audit_logs', [
            'door_assignment_id' => $assignment->id,
            'status' => DeviceAuditLog::SUCCESS,
            'attempts' => 1,
        ]);
        $this->assertSame(1, DeviceAuditLog::count());
    }

    public function test_transient_failure_retries_and_reaches_success(): void
    {
        [$assignment] = $this->assignment();
        $service = Mockery::mock(HikvisionIsapiService::class);
        $service->shouldReceive('pingDevice')->once()->andReturnFalse();
        $service->shouldNotReceive('provisionEmployeeAccess');

        try {
            (new SyncDoorAccessJob($assignment->id))->handle($service);
            $this->fail('Expected transient failure to be rethrown.');
        } catch (\RuntimeException) {
            // Expected: queue worker must receive retryable failure.
        }

        $this->assertDatabaseHas('device_audit_logs', [            'door_assignment_id' => $assignment->id,
            'status' => DeviceAuditLog::FAILED,
            'attempts' => 1,
        ]);
        $this->assertSame(1, DeviceAuditLog::count());

        $service = Mockery::mock(HikvisionIsapiService::class);
        $service->shouldReceive('pingDevice')->once()->andReturnTrue();
        $service->shouldReceive('provisionEmployeeAccess')->once()->andReturn(['status' => true]);
        (new SyncDoorAccessJob($assignment->id))->handle($service);

        $this->assertDatabaseHas('device_audit_logs', [
            'door_assignment_id' => $assignment->id,
            'status' => DeviceAuditLog::SUCCESS,
            'attempts' => 2,
        ]);
        $this->assertSame(1, DeviceAuditLog::count());
    }

    private function assignment(): array
    {
        $employee = Employee::factory()->create([
            'employee_id' => 'EMP-'.uniqid(),
            'employment_status' => 'ACTIVE',
            'card_no' => null,
        ]);
        $door = Door::create([
            'door_id' => 'DOOR-'.uniqid(),
            'door_name' => 'Test Door',
            'location' => 'Test Location',
            'device_ip' => '10.0.0.15',
        ]);
        $assignment = DoorAssignment::create([
            'employee_id' => $employee->id,
            'door_id' => $door->id,
            'sync_status' => 'pending',
        ]);
        return [$assignment, $employee, $door];
    }
}
