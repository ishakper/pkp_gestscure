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

class SyncDoorAccessAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_success_creates_audit_row_and_is_idempotently_skipped(): void
    {
        [$assignment, $employee, $door] = $this->assignment();
        $service = Mockery::mock(HikvisionIsapiService::class);
        $service->shouldReceive('pingDevice')->andReturnTrue();
        $service->shouldReceive('provisionEmployeeAccess')->andReturn(['status' => true]);

        $job = new SyncDoorAccessJob($assignment->id);
        $job->handle($service);

        // Verify assignment state after successful sync
        $assignment->refresh();
        $this->assertSame('synced', $assignment->sync_status);
        $this->assertSame(1, $assignment->sync_attempts);
        $this->assertNull($assignment->last_sync_error);

        // Verify audit log created by the job (not manually injected)
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'sync_door_success',
            'subject_type' => 'DoorAssignment',
            'subject_id' => $assignment->id,
        ]);

        // Second execution verifies idempotency
        (new SyncDoorAccessJob($assignment->id))->handle($service);
        $assignment->refresh();
        $this->assertSame('synced', $assignment->sync_status);
    }

    public function test_transient_failure_retries_and_reaches_success(): void
    {
        [$assignment, $employee, $door] = $this->assignment();

        // 1. First attempt: transient device offline failure (rethrows for queue worker retry)
        $service = Mockery::mock(HikvisionIsapiService::class);
        $service->shouldReceive('pingDevice')->once()->andReturnFalse();
        $service->shouldNotReceive('provisionEmployeeAccess');

        try {
            (new SyncDoorAccessJob($assignment->id))->handle($service);
            $this->fail('Expected transient failure to throw RuntimeException for queue retry.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('is offline', $e->getMessage());
        }

        // Verify assignment recorded failure and incremented attempts
        $assignment->refresh();
        $this->assertSame('failed', $assignment->sync_status);
        $this->assertSame(1, $assignment->sync_attempts);
        $this->assertSame('503', $assignment->last_sync_status_code);
        $this->assertStringContainsString('is offline', (string) $assignment->last_sync_error);

        // Verify audit failure logged by the job
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'sync_door_failed',
            'subject_type' => 'DoorAssignment',
            'subject_id' => $assignment->id,
            'status_code' => '503',
        ]);

        // 2. Retry attempt: device comes online, sync succeeds
        $serviceSuccess = Mockery::mock(HikvisionIsapiService::class);
        $serviceSuccess->shouldReceive('pingDevice')->once()->andReturnTrue();
        $serviceSuccess->shouldReceive('provisionEmployeeAccess')->once()->andReturn(['status' => true]);

        (new SyncDoorAccessJob($assignment->id))->handle($serviceSuccess);

        // Verify assignment reached success and attempts incremented to 2
        $assignment->refresh();
        $this->assertSame('synced', $assignment->sync_status);
        $this->assertSame(2, $assignment->sync_attempts);
        $this->assertNull($assignment->last_sync_error);
        $this->assertNull($assignment->last_sync_status_code);

        // Verify success audit logged by the job
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'sync_door_success',
            'subject_type' => 'DoorAssignment',
            'subject_id' => $assignment->id,
        ]);
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
