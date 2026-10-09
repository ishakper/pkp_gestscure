<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceCorrectionRequest;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceCorrectionService
{
    public function __construct(
        protected AttendanceProcessor $attendanceProcessor
    ) {}

    /**
     * Create and submit a new AttendanceCorrectionRequest.
     */
    public function createRequest(Admin $actor, array $data, ?UploadedFile $attachment = null): AttendanceCorrectionRequest
    {
        $role = strtolower((string) $actor->role);

        // 1. Anti-IDOR: Employee / Intern can only submit for their own linked employee
        if (in_array($role, ['employee', 'intern'], true)) {
            $employeeId = $actor->employee_id;
            if (!$employeeId) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Akun Anda tidak tertaut dengan data karyawan aktif.'],
                ]);
            }
            if (isset($data['employee_id']) && (int) $data['employee_id'] !== (int) $employeeId) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Anda tidak memiliki izin untuk membuat koreksi atas nama karyawan lain.'],
                ]);
            }
        } else {
            $employeeId = $data['employee_id'] ?? $actor->employee_id;
        }

        if (!$employeeId) {
            throw ValidationException::withMessages([
                'employee_id' => ['ID Karyawan wajib diisi.'],
            ]);
        }

        $employee = Employee::find($employeeId);
        if (!$employee) {
            throw ValidationException::withMessages([
                'employee_id' => ['Karyawan tidak ditemukan.'],
            ]);
        }

        if (isset($employee->is_active) && !$employee->is_active) {
            throw ValidationException::withMessages([
                'employee_id' => ['Karyawan tidak aktif tidak dapat mengajukan koreksi presensi.'],
            ]);
        }

        // 2. Date validation: must not be in the future
        if (empty($data['correction_date'])) {
            throw ValidationException::withMessages([
                'correction_date' => ['Tanggal koreksi wajib diisi.'],
            ]);
        }

        $correctionDate = Carbon::parse($data['correction_date'])->toDateString();
        $today = now()->toDateString();
        if ($correctionDate > $today) {
            throw ValidationException::withMessages([
                'correction_date' => ['Tanggal koreksi tidak boleh di masa mendatang.'],
            ]);
        }

        // 3. Request type validation
        $requestType = strtoupper((string) ($data['request_type'] ?? 'CHECK_IN_AND_OUT'));
        if (!in_array($requestType, AttendanceCorrectionRequest::TYPES, true)) {
            throw ValidationException::withMessages([
                'request_type' => ['Tipe koreksi tidak valid. Pilihan: ' . implode(', ', AttendanceCorrectionRequest::TYPES)],
            ]);
        }

        // 4. Time logic validation
        $requestedCheckIn  = !empty($data['requested_check_in'])  ? Carbon::parse($data['requested_check_in'])  : null;
        $requestedCheckOut = !empty($data['requested_check_out']) ? Carbon::parse($data['requested_check_out']) : null;

        if ($requestedCheckIn && $requestedCheckOut && $requestedCheckIn->gt($requestedCheckOut)) {
            throw ValidationException::withMessages([
                'requested_check_out' => ['Jam keluar (check-out) tidak boleh sebelum jam masuk (check-in).'],
            ]);
        }

        // 5. Duplicate check: prevent duplicate pending correction for same date
        $existingPending = AttendanceCorrectionRequest::where('employee_id', $employee->id)
            ->whereDate('correction_date', $correctionDate)
            ->where('status', AttendanceCorrectionRequest::STATUS_SUBMITTED)
            ->first();

        if ($existingPending) {
            throw ValidationException::withMessages([
                'correction_date' => ['Terdapat pengajuan koreksi yang sedang menunggu persetujuan untuk tanggal tersebut.'],
            ]);
        }

        // 6. Reason validation
        $reason = trim((string) ($data['reason'] ?? ''));
        if (empty($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['Alasan koreksi wajib diisi.'],
            ]);
        }

        // 7. Find existing Attendance to snapshot original values
        $existingAttendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $correctionDate)
            ->first();

        // 8. Secure document attachment handling
        $attachmentPath = null;
        if ($attachment) {
            $allowedMimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
            if (!in_array($attachment->getMimeType(), $allowedMimes, true)) {
                throw ValidationException::withMessages([
                    'attachment' => ['Lampiran harus berupa file PDF atau gambar (PNG, JPG, JPEG).'],
                ]);
            }

            if ($attachment->getSize() > 5 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'attachment' => ['Ukuran file lampiran maksimal 5MB.'],
                ]);
            }

            $ext = strtolower($attachment->getClientOriginalExtension());
            $safeFilename = 'corr_' . bin2hex(random_bytes(16)) . '.' . $ext;
            $attachmentPath = $attachment->storeAs('attendance_corrections', $safeFilename, 'local');
        }

        $source = in_array($role, ['employee', 'intern'], true)
            ? AttendanceCorrectionRequest::SOURCE_EMPLOYEE
            : ($role === 'supervisor' ? AttendanceCorrectionRequest::SOURCE_SUPERVISOR : AttendanceCorrectionRequest::SOURCE_HR_MANUAL);

        $correction = AttendanceCorrectionRequest::create([
            'employee_id'               => $employee->id,
            'attendance_id'             => $existingAttendance?->id,
            'correction_date'           => $correctionDate,
            'request_type'              => $requestType,
            'original_check_in'         => $existingAttendance?->clock_in_at,
            'original_check_out'        => $existingAttendance?->clock_out_at,
            'original_status'           => $existingAttendance?->status,
            'original_attendance_type'  => $existingAttendance?->attendance_type,
            'requested_check_in'        => $requestedCheckIn,
            'requested_check_out'       => $requestedCheckOut,
            'requested_status'          => $data['requested_status'] ?? null,
            'requested_attendance_type' => $data['requested_attendance_type'] ?? null,
            'reason'                    => $reason,
            'evidence_note'             => $data['evidence_note'] ?? null,
            'attachment_path'           => $attachmentPath,
            'correction_source'         => $source,
            'status'                    => AttendanceCorrectionRequest::STATUS_SUBMITTED,
            'submitted_at'              => now(),
            'created_by'                => $actor->id,
        ]);

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'ATTENDANCE_CORRECTION_CREATED',
            'subject_type' => 'AttendanceCorrectionRequest',
            'subject_id'   => $correction->id,
            'description'  => "Koreksi absensi #{$correction->id} untuk tanggal {$correctionDate} diajukan oleh {$actor->name}",
            'timestamp'    => now(),
        ]);

        return $correction->load(['employee', 'attendance']);
    }

    /**
     * Approve an AttendanceCorrectionRequest.
     */
    public function approveRequest(Admin $actor, AttendanceCorrectionRequest $correction): AttendanceCorrectionRequest
    {
        $this->authorizeApprovalAction($actor, $correction);

        // Anti Self-Approval
        if ((int) $actor->employee_id === (int) $correction->employee_id) {
            throw ValidationException::withMessages([
                'approval' => ['Dilarang menyetujui permohonan koreksi diri sendiri.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $correction) {
            // Lock record for update to prevent double approval race condition
            $locked = AttendanceCorrectionRequest::where('id', $correction->id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== AttendanceCorrectionRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ["Pengajuan koreksi dengan status '{$locked?->status}' tidak dapat disetujui."],
                ]);
            }

            $locked->status      = AttendanceCorrectionRequest::STATUS_APPROVED;
            $locked->approved_by = $actor->id;
            $locked->approved_at = now();
            $locked->save();

            // Apply approved correction to Attendance Core
            $this->attendanceProcessor->applyApprovedCorrection($locked);

            ActivityLog::create([
                'admin_id'     => $actor->id,
                'action'       => 'ATTENDANCE_CORRECTION_APPROVED',
                'subject_type' => 'AttendanceCorrectionRequest',
                'subject_id'   => $locked->id,
                'description'  => "Koreksi absensi #{$locked->id} tanggal {$locked->correction_date} disetujui oleh {$actor->name}",
                'timestamp'    => now(),
            ]);

            return $locked->fresh(['employee', 'attendance', 'approvedBy']);
        });
    }

    /**
     * Reject an AttendanceCorrectionRequest.
     */
    public function rejectRequest(Admin $actor, AttendanceCorrectionRequest $correction, string $reason): AttendanceCorrectionRequest
    {
        $this->authorizeApprovalAction($actor, $correction);

        if ((int) $actor->employee_id === (int) $correction->employee_id) {
            throw ValidationException::withMessages([
                'approval' => ['Dilarang menolak permohonan koreksi diri sendiri.'],
            ]);
        }

        if (empty(trim($reason))) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['Alasan penolakan wajib diisi.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $correction, $reason) {
            $locked = AttendanceCorrectionRequest::where('id', $correction->id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== AttendanceCorrectionRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ["Pengajuan koreksi dengan status '{$locked?->status}' tidak dapat ditolak."],
                ]);
            }

            $locked->status           = AttendanceCorrectionRequest::STATUS_REJECTED;
            $locked->rejected_by      = $actor->id;
            $locked->rejected_at      = now();
            $locked->rejection_reason = trim($reason);
            $locked->save();

            ActivityLog::create([
                'admin_id'     => $actor->id,
                'action'       => 'ATTENDANCE_CORRECTION_REJECTED',
                'subject_type' => 'AttendanceCorrectionRequest',
                'subject_id'   => $locked->id,
                'description'  => "Koreksi absensi #{$locked->id} tanggal {$locked->correction_date} ditolak oleh {$actor->name}: " . trim($reason),
                'timestamp'    => now(),
            ]);

            return $locked->fresh(['employee', 'attendance', 'rejectedBy']);
        });
    }

    /**
     * Cancel an AttendanceCorrectionRequest.
     */
    public function cancelRequest(Admin $actor, AttendanceCorrectionRequest $correction): AttendanceCorrectionRequest
    {
        $role = strtolower((string) $actor->role);

        $isOwner = (int) $actor->employee_id === (int) $correction->employee_id;
        $isHrOrSuper = $actor->isSuperAdmin() || in_array($role, ['hrd'], true);

        if (!$isOwner && !$isHrOrSuper) {
            throw ValidationException::withMessages([
                'authorization' => ['Anda tidak memiliki izin untuk membatalkan pengajuan koreksi ini.'],
            ]);
        }

        if ($correction->status !== AttendanceCorrectionRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => ["Pengajuan koreksi dengan status '{$correction->status}' tidak dapat dibatalkan."],
            ]);
        }

        $correction->update([
            'status'     => AttendanceCorrectionRequest::STATUS_CANCELLED,
            'updated_by' => $actor->id,
        ]);

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'ATTENDANCE_CORRECTION_CANCELLED',
            'subject_type' => 'AttendanceCorrectionRequest',
            'subject_id'   => $correction->id,
            'description'  => "Koreksi absensi #{$correction->id} dibatalkan oleh {$actor->name}",
            'timestamp'    => now(),
        ]);

        return $correction->fresh(['employee', 'attendance']);
    }

    /**
     * Authorize approval / rejection scope.
     */
    protected function authorizeApprovalAction(Admin $actor, AttendanceCorrectionRequest $correction): void
    {
        $role = strtolower((string) $actor->role);

        // Technical roles and Building Admin are strictly denied
        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            throw ValidationException::withMessages([
                'authorization' => ['Peran Anda tidak memiliki wewenang untuk menyetujui/menolak koreksi presensi.'],
            ]);
        }

        if ($actor->isSuperAdmin() || in_array($role, ['hrd', 'management'], true)) {
            return;
        }

        if ($role === 'supervisor') {
            $supervisorEmpId = $actor->employee_id ?? $actor->id;
            $employeeSupervisorId = $correction->employee?->supervisor_id;
            if ((int) $employeeSupervisorId !== (int) $supervisorEmpId) {
                throw ValidationException::withMessages([
                    'authorization' => ['Supervisor hanya berwenang menyetujui koreksi presensi anggota tim langsung.'],
                ]);
            }
            return;
        }

        throw ValidationException::withMessages([
            'authorization' => ['Anda tidak memiliki hak akses untuk memproses persetujuan ini.'],
        ]);
    }

    /**
     * Download supporting document attachment safely.
     */
    public function downloadAttachment(Admin $actor, AttendanceCorrectionRequest $correction): BinaryFileResponse
    {
        $role = strtolower((string) $actor->role);

        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            abort(403, 'Akses dokumen lampiran ditolak untuk peran Anda.');
        }

        $isOwner = (int) $actor->employee_id === (int) $correction->employee_id;
        $isSuperOrHr = $actor->isSuperAdmin() || in_array($role, ['hrd', 'management'], true);
        $isSupervisor = $role === 'supervisor' && (int) $correction->employee?->supervisor_id === (int) ($actor->employee_id ?? $actor->id);

        if (!$isOwner && !$isSuperOrHr && !$isSupervisor) {
            abort(403, 'Akses dokumen lampiran koreksi tidak diizinkan.');
        }

        if (!$correction->attachment_path || !Storage::disk('local')->exists($correction->attachment_path)) {
            abort(404, 'Dokumen lampiran tidak ditemukan.');
        }

        // Path traversal defense
        $realPath = Storage::disk('local')->path($correction->attachment_path);
        $expectedDir = realpath(Storage::disk('local')->path('attendance_corrections'));
        if ($expectedDir && !str_starts_with(realpath($realPath) ?: '', $expectedDir)) {
            abort(403, 'Akses path tidak valid.');
        }

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'ATTENDANCE_CORRECTION_DOCUMENT_ACCESSED',
            'subject_type' => 'AttendanceCorrectionRequest',
            'subject_id'   => $correction->id,
            'description'  => "Dokumen lampiran koreksi #{$correction->id} diakses oleh {$actor->name}",
            'timestamp'    => now(),
        ]);

        return response()->download($realPath, basename($correction->attachment_path));
    }
}
