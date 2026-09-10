<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\DoorAssignment;
use App\Services\HikvisionIsapiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncDoorAccessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 5; // Seconds to wait between retries

    protected int $assignmentId;

    public function __construct(int $assignmentIdOrEmployeeId, ?int $doorId = null)
    {
        if ($doorId !== null) {
            $assignment = DoorAssignment::firstOrCreate(
                ['employee_id' => $assignmentIdOrEmployeeId, 'door_id' => $doorId],
                ['sync_status' => 'pending']
            );
            $this->assignmentId = $assignment->id;
        } else {
            $this->assignmentId = $assignmentIdOrEmployeeId;
        }
    }

    public function handle(HikvisionIsapiService $isapiService): void
    {
        $assignment = DoorAssignment::with(['employee', 'door'])->find($this->assignmentId);

        if (!$assignment || !$assignment->employee || !$assignment->door) {
            Log::warning("SyncDoorAccessJob: Invalid assignment ID {$this->assignmentId}");
            return;
        }

        $employee = $assignment->employee;
        $door = $assignment->door;

        Log::info("Processing SyncDoorAccessJob for Employee {$employee->employee_id} -> Door {$door->door_id} (Attempt: " . ($assignment->sync_attempts + 1) . ")");

        // Increment attempts count
        $assignment->increment('sync_attempts');

        // Check if door is online
        $isOnline = $isapiService->pingDevice($door);
        if (!$isOnline) {
            $errorMsg = "Device {$door->door_id} ({$door->device_ip}) is offline";
            $assignment->update([
                'sync_status' => 'failed',
                'last_sync_error' => $errorMsg,
            ]);
            
            ActivityLog::create([
                'admin_id' => auth()->id() ?? null,
                'action' => 'sync_door_failed',
                'subject_type' => 'DoorAssignment',
                'subject_id' => $assignment->id,
                'description' => "Sync failed: {$errorMsg}",
                'timestamp' => now(),
            ]);

            return;
        }

        // Provision User Profile, Biometric/Card, and Access Rights
        $provisionResult = $isapiService->provisionEmployeeAccess($door, $employee);
        if (!$provisionResult['status']) {
            $errorMsg = $provisionResult['error'] ?? 'Biometric user provisioning failed';
            $assignment->update([
                'sync_status' => 'failed',
                'last_sync_error' => $errorMsg,
            ]);
            throw new \Exception($errorMsg);
        }

        // Successfully synced
        $assignment->update([
            'sync_status' => 'synced',
            'last_sync_error' => null,
            'last_synced_at' => now(),
            'user_info_synced_at' => now(),
            'card_synced_at' => !empty($employee->card_no) ? now() : null,
            'sync_type' => 'FULL',
        ]);

        ActivityLog::create([
            'admin_id' => auth()->id() ?? null,
            'action' => 'sync_door_success',
            'subject_type' => 'DoorAssignment',
            'subject_id' => $assignment->id,
            'description' => "Successfully provisioned biometric profile for {$employee->name} ({$employee->employee_id}) to Door {$door->door_id}",
            'timestamp' => now(),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        $assignment = DoorAssignment::with(['employee', 'door'])->find($this->assignmentId);
        if ($assignment) {
            $assignment->update([
                'sync_status' => 'failed',
                'last_sync_error' => $exception->getMessage(),
            ]);
            
            ActivityLog::create([
                'admin_id' => null,
                'action' => 'sync_door_max_retries_failed',
                'subject_type' => 'DoorAssignment',
                'subject_id' => $assignment->id,
                'description' => "Sync failed after maximum retries for assignment ID {$assignment->id}: {$exception->getMessage()}",
                'timestamp' => now(),
            ]);
        }
    }
}
