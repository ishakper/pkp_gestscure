<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceRequestService
{
    protected AttendanceProcessor $attendanceProcessor;

    public function __construct(AttendanceProcessor $attendanceProcessor)
    {
        $this->attendanceProcessor = $attendanceProcessor;
    }

    /**
     * Create and submit a new AttendanceRequest (WFH, LEAVE, PERMISSION, SICK).
     */
    public function createRequest(Admin $actor, array $data, ?UploadedFile $attachment = null): AttendanceRequest
    {
        $role = strtolower((string) $actor->role);

        // 1. Resolve & enforce employee identity (Anti-IDOR)
        if (in_array($role, ['employee', 'intern'], true)) {
            $employeeId = $actor->employee_id;
            if (!$employeeId) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Akun Anda tidak tertaut dengan data karyawan aktif.'],
                ]);
            }
            if (isset($data['employee_id']) && (int) $data['employee_id'] !== (int) $employeeId) {
                throw ValidationException::withMessages([
                    'employee_id' => ['Anda tidak memiliki izin untuk membuat permohonan atas nama karyawan lain.'],
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

        // Check employee active status
        if (isset($employee->is_active) && !$employee->is_active) {
            throw ValidationException::withMessages([
                'employee_id' => ['Karyawan tidak aktif tidak dapat mengajukan permohonan.'],
            ]);
        }

        // 2. Validate request type
        $type = strtoupper((string) ($data['request_type'] ?? ''));
        if (!in_array($type, AttendanceRequest::TYPES, true)) {
            throw ValidationException::withMessages([
                'request_type' => ['Tipe permohonan tidak valid. Pilihan: ' . implode(', ', AttendanceRequest::TYPES)],
            ]);
        }

        // 3. Validate date range
        $startDate = Carbon::parse($data['start_date'])->toDateString();
        $endDate   = Carbon::parse($data['end_date'] ?? $startDate)->toDateString();

        if ($startDate > $endDate) {
            throw ValidationException::withMessages([
                'end_date' => ['Tanggal selesai tidak boleh sebelum tanggal mulai.'],
            ]);
        }

        if (Carbon::parse($startDate)->diffInDays(Carbon::parse($endDate)) > 60) {
            throw ValidationException::withMessages([
                'end_date' => ['Durasi permohonan maksimal adalah 60 hari.'],
            ]);
        }

        // 4. Overlap validation: prevent conflicting active requests
        $existingOverlap = AttendanceRequest::where('employee_id', $employee->id)
            ->whereIn('status', [AttendanceRequest::STATUS_SUBMITTED, AttendanceRequest::STATUS_APPROVED])
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereDate('start_date', '<=', $endDate)
                  ->whereDate('end_date', '>=', $startDate);
            })
            ->first();

        if ($existingOverlap) {
            throw ValidationException::withMessages([
                'start_date' => [
                    "Permohonan bentrok dengan pengajuan {$existingOverlap->request_type} yang sudah aktif (ID: #{$existingOverlap->id}, {$existingOverlap->start_date} s/d {$existingOverlap->end_date})."
                ],
            ]);
        }

        // 5. Attachment handling (private storage)
        $attachmentPath = null;
        $attachmentMime = null;
        $attachmentSizeBytes = null;
        $attachmentOriginalName = null;

        if ($attachment) {
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            $mime = $attachment->getMimeType();
            if (!in_array($mime, $allowedMimes, true)) {
                throw ValidationException::withMessages([
                    'attachment' => ['Format berkas tidak didukung. Format yang diperbolehkan: PDF, JPG, PNG, WEBP.'],
                ]);
            }

            if ($attachment->getSize() > 5 * 1024 * 1024) {
                throw ValidationException::withMessages([
                    'attachment' => ['Ukuran berkas melebihi batas maksimal 5 MB.'],
                ]);
            }

            $originalName = $attachment->getClientOriginalName();
            $ext = $attachment->getClientOriginalExtension() ?: 'bin';
            $safeName = bin2hex(random_bytes(16)) . '.' . $ext;
            $subPath = 'attendance_requests/attachments';

            $storedPath = Storage::disk('local')->putFileAs($subPath, $attachment, $safeName);

            $attachmentPath = $storedPath;
            $attachmentMime = $mime;
            $attachmentSizeBytes = $attachment->getSize();
            $attachmentOriginalName = $originalName;
        }

        // 6. Create AttendanceRequest record
        $request = AttendanceRequest::create([
            'employee_id'              => $employee->id,
            'request_type'             => $type,
            'status'                   => AttendanceRequest::STATUS_SUBMITTED,
            'category'                 => $data['category'] ?? null,
            'start_date'               => $startDate,
            'end_date'                 => $endDate,
            'start_time'               => $data['start_time'] ?? null,
            'end_time'                 => $data['end_time'] ?? null,
            'reason'                   => trim((string) ($data['reason'] ?? '')),
            'attachment_path'          => $attachmentPath,
            'attachment_mime'          => $attachmentMime,
            'attachment_size_bytes'    => $attachmentSizeBytes,
            'attachment_original_name' => $attachmentOriginalName,
            'metadata'                 => $data['metadata'] ?? null,
            'submitted_at'             => now(),
            'created_by'               => $actor->id,
        ]);

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'ATTENDANCE_REQUEST_CREATED',
            'subject_type' => 'AttendanceRequest',
            'subject_id'   => $request->id,
            'description'  => "Permohonan absensi #{$request->id} ({$type}) berhasil diajukan untuk periode {$startDate} s/d {$endDate}",
            'timestamp'    => now(),
        ]);

        return $request->load(['employee', 'approver', 'rejecter']);
    }

    /**
     * Approve an AttendanceRequest.
     */
    public function approveRequest(Admin $actor, AttendanceRequest $request, ?string $notes = null): AttendanceRequest
    {
        $this->authorizeApprovalAction($actor, $request);

        // Anti Self-Approval
        if ((int) $actor->employee_id === (int) $request->employee_id) {
            throw ValidationException::withMessages([
                'approval' => ['Dilarang menyetujui permohonan diri sendiri.'],
            ]);
        }

        // Deterministic lifecycle: only SUBMITTED can be approved
        if ($request->status !== AttendanceRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => ["Permohonan dengan status '{$request->status}' tidak dapat disetujui."],
            ]);
        }

        $request->update([
            'status'      => AttendanceRequest::STATUS_APPROVED,
            'approved_by' => $actor->id,
            'approved_at' => now(),
        ]);

        // Integrate with Attendance Core
        $this->attendanceProcessor->integrateApprovedRequest($request);

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'ATTENDANCE_REQUEST_APPROVED',
            'subject_type' => 'AttendanceRequest',
            'subject_id'   => $request->id,
            'description'  => "Permohonan absensi #{$request->id} ({$request->request_type}) disetujui oleh {$actor->name}",
            'timestamp'    => now(),
        ]);

        return $request->fresh(['employee', 'approver']);
    }

    /**
     * Reject an AttendanceRequest.
     */
    public function rejectRequest(Admin $actor, AttendanceRequest $request, string $reason): AttendanceRequest
    {
        $this->authorizeApprovalAction($actor, $request);

        if ((int) $actor->employee_id === (int) $request->employee_id) {
            throw ValidationException::withMessages([
                'approval' => ['Dilarang menolak permohonan diri sendiri.'],
            ]);
        }

        if ($request->status !== AttendanceRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => ["Permohonan dengan status '{$request->status}' tidak dapat ditolak."],
            ]);
        }

        if (empty(trim($reason))) {
            throw ValidationException::withMessages([
                'reason' => ['Alasan penolakan wajib diisi.'],
            ]);
        }

        $request->update([
            'status'           => AttendanceRequest::STATUS_REJECTED,
            'rejected_by'      => $actor->id,
            'rejected_at'      => now(),
            'rejection_reason' => trim($reason),
        ]);

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'ATTENDANCE_REQUEST_REJECTED',
            'subject_type' => 'AttendanceRequest',
            'subject_id'   => $request->id,
            'description'  => "Permohonan absensi #{$request->id} ({$request->request_type}) ditolak. Alasan: {$reason}",
            'timestamp'    => now(),
        ]);

        return $request->fresh(['employee', 'rejecter']);
    }

    /**
     * Cancel an AttendanceRequest.
     */
    public function cancelRequest(Admin $actor, AttendanceRequest $request, ?string $reason = null): AttendanceRequest
    {
        $role = strtolower((string) $actor->role);
        $isOwner = ((int) $actor->employee_id === (int) $request->employee_id);
        $isHrdOrAdmin = in_array($role, ['super_admin', 'hrd'], true);

        if (!$isOwner && !$isHrdOrAdmin) {
            throw ValidationException::withMessages([
                'cancel' => ['Anda tidak memiliki wewenang untuk membatalkan permohonan ini.'],
            ]);
        }

        // Owner can only cancel DRAFT or SUBMITTED
        if ($isOwner && !$isHrdOrAdmin && $request->status === AttendanceRequest::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'cancel' => ['Permohonan yang telah disetujui hanya dapat dibatalkan oleh HRD.'],
            ]);
        }

        if (in_array($request->status, [AttendanceRequest::STATUS_REJECTED, AttendanceRequest::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages([
                'status' => ["Permohonan sudah berstatus {$request->status}."],
            ]);
        }

        $wasApproved = ($request->status === AttendanceRequest::STATUS_APPROVED);

        $request->update([
            'status'              => AttendanceRequest::STATUS_CANCELLED,
            'cancelled_by'        => $actor->id,
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason ? trim($reason) : 'Dibatalkan oleh pemohon',
        ]);

        // If previously approved, recalculate covered dates
        if ($wasApproved) {
            $this->revertApprovedRequest($request);
        }

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'ATTENDANCE_REQUEST_CANCELLED',
            'subject_type' => 'AttendanceRequest',
            'subject_id'   => $request->id,
            'description'  => "Permohonan absensi #{$request->id} dibatalkan oleh {$actor->name}",
            'timestamp'    => now(),
        ]);

        return $request->fresh(['employee']);
    }

    /**
     * Authorize approval/rejection action for an actor.
     */
    public function authorizeApprovalAction(Admin $actor, AttendanceRequest $request): void
    {
        $role = strtolower((string) $actor->role);

        // Technical roles explicitly denied
        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                "Peran '{$role}' tidak memiliki izin mengelola permohonan absensi karyawan."
            );
        }

        // Super Admin and HRD have org-wide authority
        if ($actor->isSuperAdmin() || in_array($role, ['hrd', 'management'], true)) {
            return;
        }

        // Supervisor can only approve/reject assigned team
        if ($role === 'supervisor') {
            $employee = $request->employee;
            $supervisorEmpId = $actor->employee_id ?? $actor->id;

            if ($employee && (int) $employee->supervisor_id === (int) $supervisorEmpId) {
                return;
            }

            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Supervisor hanya dapat menyetujui permohonan anggota tim yang dibimbing.'
            );
        }

        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Anda tidak memiliki wewenang untuk memproses permohonan ini.'
        );
    }

    /**
     * Download or view private supporting document with authorization.
     */
    public function downloadAttachment(Admin $actor, AttendanceRequest $request): BinaryFileResponse
    {
        $role = strtolower((string) $actor->role);
        $isOwner = ((int) $actor->employee_id === (int) $request->employee_id);
        $isHrdOrAdmin = in_array($role, ['super_admin', 'hrd', 'management'], true);
        $isSupervisor = ($role === 'supervisor' && (int) $request->employee?->supervisor_id === (int) ($actor->employee_id ?? $actor->id));

        if (!$isOwner && !$isHrdOrAdmin && !$isSupervisor) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Anda tidak memiliki akses untuk mengunduh dokumen pendukung ini.'
            );
        }

        if (!$request->attachment_path || !Storage::disk('local')->exists($request->attachment_path)) {
            abort(404, 'Dokumen pendukung tidak ditemukan.');
        }

        $fullPath = Storage::disk('local')->path($request->attachment_path);

        return response()->download(
            $fullPath,
            $request->attachment_original_name ?? basename($request->attachment_path),
            ['Content-Type' => $request->attachment_mime ?? 'application/octet-stream']
        );
    }

    /**
     * Revert attendance records when an approved request is cancelled.
     */
    protected function revertApprovedRequest(AttendanceRequest $request): void
    {
        $start = Carbon::parse($request->start_date)->startOfDay();
        $end   = Carbon::parse($request->end_date)->startOfDay();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $attendance = Attendance::where('employee_id', $request->employee_id)
                ->whereDate('attendance_date', $cursor->toDateString())
                ->first();

            if ($attendance) {
                // If it has no physical clock-in, reset back to computeStatus
                if (!$attendance->clock_in_at) {
                    $calendar = $this->attendanceProcessor->resolveCalendar($request->employee, $cursor);
                    $computed = $this->attendanceProcessor->computeStatus(null, null, $calendar, $cursor, $request->employee);
                    $attendance->update([
                        'status'          => $computed['status'],
                        'attendance_type' => 'OFFICE',
                        'notes'           => null,
                    ]);
                } else {
                    if ($attendance->attendance_type === 'WFH') {
                        $attendance->update(['attendance_type' => 'OFFICE']);
                    }
                }
            }
            $cursor->addDay();
        }
    }
}
