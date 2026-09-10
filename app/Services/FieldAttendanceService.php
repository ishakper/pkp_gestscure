<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\Employee;
use App\Models\FieldAssignment;
use App\Models\FieldAttendanceEvidence;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FieldAttendanceService
{
    public function __construct(
        protected GeofenceService $geofenceService,
        protected AttendanceProcessor $attendanceProcessor
    ) {}

    /**
     * Resolve active field assignment for an employee on a given date.
     */
    public function resolveActiveAssignment(Employee $employee, Carbon $date): ?FieldAssignment
    {
        $dateStr = $date->toDateString();

        return FieldAssignment::with('fieldLocation')
            ->where('employee_id', $employee->id)
            ->where('status', 'ACTIVE')
            ->where('start_date', '<=', $dateStr)
            ->where('end_date', '>=', $dateStr)
            ->first();
    }

    /**
     * Submit field check-in evidence.
     */
    public function recordCheckIn(
        Admin $actor,
        Employee $employee,
        array $gpsData,
        UploadedFile $photo,
        ?string $notes = null
    ): FieldAttendanceEvidence {
        $now = now();
        $date = $now->copy()->startOfDay();

        // 1. Check authorized field assignment
        $assignment = $this->resolveActiveAssignment($employee, $date);
        if (!$assignment) {
            throw ValidationException::withMessages([
                'assignment' => ['Karyawan tidak memiliki penugasan lapangan yang aktif untuk hari ini.'],
            ]);
        }

        $location = $assignment->fieldLocation;
        if (!$location || !$location->isCurrentlyValid()) {
            throw ValidationException::withMessages([
                'location' => ['Lokasi penugasan lapangan saat ini sedang tidak aktif.'],
            ]);
        }

        // 2. Prevent duplicate check-in today
        $existingAttendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        if ($existingAttendance && $existingAttendance->clock_in_at) {
            throw ValidationException::withMessages([
                'check_in' => ['Anda sudah melakukan presensi masuk (check-in) untuk hari ini.'],
            ]);
        }

        $existingEvidence = FieldAttendanceEvidence::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->where('type', 'CHECK_IN')
            ->first();

        if ($existingEvidence && $existingEvidence->isVerified()) {
            throw ValidationException::withMessages([
                'check_in' => ['Bukti check-in lapangan untuk hari ini sudah tercatat dan terverifikasi.'],
            ]);
        }

        // 3. Server-side Geofence validation
        $lat = (float) $gpsData['latitude'];
        $lon = (float) $gpsData['longitude'];
        $accuracy = (float) $gpsData['accuracy_meters'];
        $capturedAt = isset($gpsData['captured_at']) ? Carbon::parse($gpsData['captured_at']) : $now;

        $geofence = $this->geofenceService->validateGeofence($lat, $lon, $accuracy, $location);

        // 4. Anomaly checks
        $anomalies = $this->geofenceService->detectAnomalies($employee, $lat, $lon, $accuracy, $capturedAt, $now);

        // 5. Store private photo evidence securely
        $storedPhoto = $this->storePrivatePhoto($photo);

        return DB::transaction(function () use (
            $actor,
            $employee,
            $assignment,
            $location,
            $date,
            $lat,
            $lon,
            $accuracy,
            $capturedAt,
            $now,
            $geofence,
            $storedPhoto,
            $anomalies,
            $notes
        ) {
            $evidence = FieldAttendanceEvidence::create([
                'employee_id' => $employee->id,
                'field_assignment_id' => $assignment->id,
                'field_location_id' => $location->id,
                'attendance_date' => $date->toDateString(),
                'type' => 'CHECK_IN',
                'latitude' => $lat,
                'longitude' => $lon,
                'accuracy_meters' => $accuracy,
                'captured_at' => $capturedAt,
                'server_received_at' => $now,
                'distance_meters' => $geofence['distance_meters'],
                'geofence_result' => $geofence['geofence_result'],
                'photo_path' => $storedPhoto['path'],
                'photo_mime' => $storedPhoto['mime'],
                'photo_size_bytes' => $storedPhoto['size'],
                'anomaly_flags' => !empty($anomalies) ? $anomalies : null,
                'notes' => $notes,
            ]);

            // If geofence is valid, process into Attendance Core
            if ($geofence['is_valid']) {
                $this->attendanceProcessor->processFieldEvidence($evidence);
            }

            // Audit
            ActivityLog::create([
                'admin_id' => $actor->id,
                'action' => 'FIELD_CHECK_IN',
                'subject_type' => 'FieldAttendanceEvidence',
                'subject_id' => $evidence->id,
                'description' => "Presensi check-in lapangan diajukan untuk karyawan {$employee->name} di {$location->name} (Hasil Geofence: {$evidence->geofence_result}, Jarak: {$evidence->distance_meters}m)",
                'timestamp' => $now,
            ]);

            return $evidence->load(['fieldLocation', 'fieldAssignment', 'attendance']);
        });
    }

    /**
     * Submit field check-out evidence.
     */
    public function recordCheckOut(
        Admin $actor,
        Employee $employee,
        array $gpsData,
        UploadedFile $photo,
        ?string $notes = null
    ): FieldAttendanceEvidence {
        $now = now();
        $date = $now->copy()->startOfDay();

        // 1. Check authorized field assignment
        $assignment = $this->resolveActiveAssignment($employee, $date);
        if (!$assignment) {
            throw ValidationException::withMessages([
                'assignment' => ['Karyawan tidak memiliki penugasan lapangan yang aktif untuk hari ini.'],
            ]);
        }

        $location = $assignment->fieldLocation;
        if (!$location || !$location->isCurrentlyValid()) {
            throw ValidationException::withMessages([
                'location' => ['Lokasi penugasan lapangan saat ini sedang tidak aktif.'],
            ]);
        }

        // 2. Ensure check-in exists for today
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date->toDateString())
            ->first();

        if (!$attendance || !$attendance->clock_in_at) {
            throw ValidationException::withMessages([
                'check_out' => ['Anda belum melakukan presensi masuk (check-in) untuk hari ini.'],
            ]);
        }

        if ($attendance->clock_out_at) {
            throw ValidationException::withMessages([
                'check_out' => ['Anda sudah melakukan presensi pulang (check-out) untuk hari ini.'],
            ]);
        }

        // 3. Server-side Geofence validation
        $lat = (float) $gpsData['latitude'];
        $lon = (float) $gpsData['longitude'];
        $accuracy = (float) $gpsData['accuracy_meters'];
        $capturedAt = isset($gpsData['captured_at']) ? Carbon::parse($gpsData['captured_at']) : $now;

        $geofence = $this->geofenceService->validateGeofence($lat, $lon, $accuracy, $location);

        // 4. Anomaly checks
        $anomalies = $this->geofenceService->detectAnomalies($employee, $lat, $lon, $accuracy, $capturedAt, $now);

        // 5. Store private photo evidence securely
        $storedPhoto = $this->storePrivatePhoto($photo);

        return DB::transaction(function () use (
            $actor,
            $employee,
            $assignment,
            $location,
            $date,
            $lat,
            $lon,
            $accuracy,
            $capturedAt,
            $now,
            $geofence,
            $storedPhoto,
            $anomalies,
            $notes
        ) {
            $evidence = FieldAttendanceEvidence::create([
                'employee_id' => $employee->id,
                'field_assignment_id' => $assignment->id,
                'field_location_id' => $location->id,
                'attendance_date' => $date->toDateString(),
                'type' => 'CHECK_OUT',
                'latitude' => $lat,
                'longitude' => $lon,
                'accuracy_meters' => $accuracy,
                'captured_at' => $capturedAt,
                'server_received_at' => $now,
                'distance_meters' => $geofence['distance_meters'],
                'geofence_result' => $geofence['geofence_result'],
                'photo_path' => $storedPhoto['path'],
                'photo_mime' => $storedPhoto['mime'],
                'photo_size_bytes' => $storedPhoto['size'],
                'anomaly_flags' => !empty($anomalies) ? $anomalies : null,
                'notes' => $notes,
            ]);

            // If geofence is valid, process into Attendance Core
            if ($geofence['is_valid']) {
                $this->attendanceProcessor->processFieldEvidence($evidence);
            }

            // Audit
            ActivityLog::create([
                'admin_id' => $actor->id,
                'action' => 'FIELD_CHECK_OUT',
                'subject_type' => 'FieldAttendanceEvidence',
                'subject_id' => $evidence->id,
                'description' => "Presensi check-out lapangan diajukan untuk karyawan {$employee->name} di {$location->name} (Hasil Geofence: {$evidence->geofence_result}, Jarak: {$evidence->distance_meters}m)",
                'timestamp' => $now,
            ]);

            return $evidence->load(['fieldLocation', 'fieldAssignment', 'attendance']);
        });
    }

    /**
     * Manual override by authorized HRD/Admin.
     */
    public function manualOverride(Admin $actor, FieldAttendanceEvidence $evidence, string $reason): FieldAttendanceEvidence
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => ['Alasan manual override wajib diisi secara spesifik.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $evidence, $reason) {
            $evidence->is_override = true;
            $evidence->override_reason = $reason;
            $evidence->override_by = $actor->id;
            $evidence->override_at = now();
            $evidence->save();

            // Original GPS, distance and photo are preserved, but status allows AttendanceProcessor integration
            $this->attendanceProcessor->processFieldEvidence($evidence);

            ActivityLog::create([
                'admin_id' => $actor->id,
                'action' => 'FIELD_ATTENDANCE_OVERRIDE',
                'subject_type' => 'FieldAttendanceEvidence',
                'subject_id' => $evidence->id,
                'description' => "Manual override presensi lapangan ID {$evidence->id} untuk karyawan ID {$evidence->employee_id} oleh {$actor->name}. Alasan: {$reason}",
                'timestamp' => now(),
            ]);

            return $evidence->fresh(['fieldLocation', 'fieldAssignment', 'attendance']);
        });
    }

    /**
     * Store private photo evidence on private disk with random generated filename.
     */
    protected function storePrivatePhoto(UploadedFile $photo): array
    {
        $diskName = config('field_attendance.photo_disk', 'local');
        $directory = config('field_attendance.photo_directory', 'field_photos');

        $ext = strtolower($photo->getClientOriginalExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $dateFolder = now()->format('Y/m');
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $path = "{$directory}/{$dateFolder}/{$filename}";

        Storage::disk($diskName)->putFileAs("{$directory}/{$dateFolder}", $photo, $filename);

        return [
            'path' => $path,
            'mime' => $photo->getClientMimeType() ?: 'image/jpeg',
            'size' => $photo->getSize() ?: 0,
        ];
    }

    /**
     * Verify authorization to view/download private field photo.
     */
    public function canAccessPhoto(Admin $actor, FieldAttendanceEvidence $evidence): bool
    {
        $role = strtolower((string) $actor->role);

        // Super Admin & HRD have management authorization
        if (in_array($role, ['super_admin', 'hrd'], true)) {
            return true;
        }

        // Supervisor can view for assigned team members
        if ($role === 'supervisor') {
            $emp = $evidence->employee;
            if ($emp && $emp->supervisor_id === $actor->employee_id) {
                return true;
            }
            if ($evidence->fieldAssignment && $evidence->fieldAssignment->supervisor_id === $actor->employee_id) {
                return true;
            }
            return false;
        }

        // Employee / Intern can only view their own photo
        if (in_array($role, ['employee', 'intern'], true)) {
            return (int)$evidence->employee_id === (int)$actor->employee_id;
        }

        // Management (summary scope only, no photo), Building Admin, Developer, DevOps, Infra, Security -> DENIED
        return false;
    }
}
