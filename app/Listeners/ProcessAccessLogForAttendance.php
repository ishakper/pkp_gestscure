<?php

namespace App\Listeners;

use App\Events\AccessLogCreated;
use App\Models\AttendanceEvidence;
use App\Services\AttendanceProcessor;
use Illuminate\Support\Facades\Log;

class ProcessAccessLogForAttendance
{
    public function __construct(private readonly AttendanceProcessor $processor)
    {
    }

    public function handle(AccessLogCreated $event): void
    {
        $accessLog = $event->accessLog;

        // Physical access remains immutable. Attendance only consumes a granted,
        // mapped standard tap; denied and unmapped attempts remain AccessLog-only.
        if ($accessLog->event_type !== 'STANDARD_TAP' || $accessLog->access_status !== 'Granted' || !$accessLog->employee_id) {
            return;
        }

        $direction = strtoupper((string) $accessLog->getAttribute('attendance_direction'));
        if (!in_array($direction, ['ENTRY', 'EXIT', 'UNKNOWN'], true)) {
            $direction = 'UNKNOWN';
        }

        $evidence = AttendanceEvidence::firstOrCreate(
            ['access_log_id' => $accessLog->id],
            [
                'employee_id' => $accessLog->employee_id,
                'door_id' => $accessLog->door_id,
                'event_timestamp' => $accessLog->timestamp,
                'direction' => $direction,
                'credential_type' => $accessLog->verify_method,
                'hardware_serial' => $accessLog->device_serial,
                'status' => 'MAPPED',
            ],
        );

        if (!$evidence->wasRecentlyCreated) {
            return;
        }

        try {
            $attendance = $this->processor->processEvidence($evidence);
            if ($attendance && $evidence->attendance_id !== $attendance->id) {
                $evidence->update(['attendance_id' => $attendance->id]);
            }
        } catch (\Throwable $exception) {
            // The security record and normalized evidence survive processor failures
            // so a later reconciliation can retry without replaying the device event.
            $evidence->update(['status' => 'PROCESSING_FAILED']);
            Log::error('[Attendance integration] Evidence processing failed', [
                'access_log_id' => $accessLog->id,
                'attendance_evidence_id' => $evidence->id,
                'exception_class' => $exception::class,
                'exception_location' => basename($exception->getFile()) . ':' . $exception->getLine(),
            ]);
            return;
        }

        Log::info('[Attendance integration] Evidence processed', [
            'access_log_id' => $accessLog->id,
            'attendance_evidence_id' => $evidence->id,
            'employee_id' => $evidence->employee_id,
            'direction' => $evidence->direction,
            'attendance_id' => $attendance?->id,
            'result' => $attendance ? 'UPDATED' : 'IGNORED',
        ]);
    }
}
