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

    public int $tries = 1;

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

        if (strtoupper(trim((string) $employee->employment_status)) !== 'ACTIVE') {
            $error = 'Provisioning blocked: employee employment_status is not ACTIVE.';
            $assignment->update([
                'sync_status' => 'failed',
                'last_sync_error' => $error,
                'failed_step' => null,
                'last_sync_status_code' => 'INACTIVE',
            ]);
            $this->auditFailure($assignment, null, 'INACTIVE', $error, 'sync_door_blocked');

            return;
        }

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
                'failed_step' => null,
                'last_sync_status_code' => '503',
            ]);
            $this->auditFailure($assignment, null, '503', $errorMsg);

            return;
        }

        // Provision User Profile, Biometric/Card, and Access Rights
        $provisionResult = $isapiService->provisionEmployeeAccess($door, $employee);
        if (!$provisionResult['status']) {
            $failedStep = $provisionResult['failed_step'] ?? null;
            $statusCode = $this->safeStatusCode($provisionResult['statusCode'] ?? null);
            $safeError = $this->sanitizeDiagnosticError($provisionResult['error'] ?? null);
            $errorMsg = mb_substr($failedStep ? "{$failedStep}: {$safeError}" : $safeError, 0, 500);
            $assignment->update([
                'sync_status' => 'failed',
                'last_sync_error' => $errorMsg,
                'failed_step' => $failedStep,
                'last_sync_status_code' => $statusCode,
            ]);
            $this->auditFailure($assignment, $failedStep, $statusCode, $safeError);

            return;
        }

        // Successfully synced
        $assignment->update([
            'sync_status' => 'synced',
            'last_sync_error' => null,
            'failed_step' => null,
            'last_sync_status_code' => null,
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
                'last_sync_error' => 'JOB: unexpected worker failure',
                'failed_step' => null,
                'last_sync_status_code' => null,
            ]);
            $this->auditFailure($assignment, null, null, 'Unexpected worker failure', 'sync_door_job_failed');
        }
    }

    private function auditFailure(DoorAssignment $assignment, ?string $failedStep, ?string $statusCode, string $error, string $action = 'sync_door_failed'): void
    {
        ActivityLog::create([
            'admin_id' => auth()->id() ?? null,
            'action' => $action,
            'subject_type' => 'DoorAssignment',
            'subject_id' => $assignment->id,
            'assignment_id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'door_id' => $assignment->door_id,
            'failed_step' => $failedStep,
            'status_code' => $statusCode,
            'error' => $error,
            'description' => sprintf('Door sync failed. FAILED_STEP=%s STATUS_CODE=%s ERROR=%s', $failedStep ?? 'NONE', $statusCode ?? 'NONE', $error),
            'timestamp' => now(),
        ]);
    }

    private function safeStatusCode(mixed $statusCode): ?string
    {
        if (!is_int($statusCode) && !is_string($statusCode)) {
            return null;
        }

        $statusCode = trim((string) $statusCode);

        return preg_match('/^(?:\d{3}|0x[0-9a-f]+)$/i', $statusCode) ? $statusCode : null;
    }

    private function sanitizeDiagnosticError(mixed $error): string
    {
        if (!is_scalar($error)) {
            return 'Provisioning failed without a safe diagnostic message';
        }

        $error = trim(preg_replace('/\s+/', ' ', (string) $error));
        $secretKey = '(?:password|authorization|token|card(?:_no)?|credential(?:[_\s-]?secret)?)';
        $error = preg_replace('/\bauthorization\b\s*[:=]\s*(?:Bearer\s+)?[^\s,;]+/i', '[REDACTED]', $error);
        $error = preg_replace('/["\']' . $secretKey . '["\']\s*:\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;}]+)/i', '[REDACTED]', $error);
        $error = preg_replace('/\b' . $secretKey . '\b\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '[REDACTED]', $error);
        $error = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/=:-]+/i', '[REDACTED]', $error);
        $error = trim((string) $error);

        return mb_substr($error !== '' ? $error : 'Provisioning failed without a safe diagnostic message', 0, 500);
    }
}
