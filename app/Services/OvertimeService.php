<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Attendance;
use App\Models\AttendanceRequest;
use App\Models\Employee;
use App\Models\OvertimeRequest;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OvertimeService
{
    public function __construct(
        protected AttendanceProcessor $attendanceProcessor
    ) {}

    /**
     * Create and submit a new OvertimeRequest.
     */
    public function createRequest(Admin $actor, array $data, ?UploadedFile $attachment = null): OvertimeRequest
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
                    'employee_id' => ['Anda tidak memiliki izin untuk mengajukan lembur atas nama karyawan lain.'],
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
                'employee_id' => ['Karyawan tidak aktif tidak dapat mengajukan lembur.'],
            ]);
        }

        // 2. Date validation
        if (empty($data['overtime_date'])) {
            throw ValidationException::withMessages([
                'overtime_date' => ['Tanggal lembur wajib diisi.'],
            ]);
        }

        $overtimeDate = Carbon::parse($data['overtime_date'])->toDateString();

        // 3. Prevent overtime during approved Leave or Sick
        $hasApprovedLeaveOrSick = AttendanceRequest::where('employee_id', $employee->id)
            ->where('status', AttendanceRequest::STATUS_APPROVED)
            ->whereIn('request_type', [AttendanceRequest::TYPE_LEAVE, AttendanceRequest::TYPE_SICK])
            ->whereDate('start_date', '<=', $overtimeDate)
            ->whereDate('end_date', '>=', $overtimeDate)
            ->exists();

        if ($hasApprovedLeaveOrSick) {
            throw ValidationException::withMessages([
                'overtime_date' => ['Tidak dapat mengajukan lembur pada tanggal saat sedang cuti atau sakit.'],
            ]);
        }

        // 4. Start and End time validation
        if (empty($data['requested_start']) || empty($data['requested_end'])) {
            throw ValidationException::withMessages([
                'requested_start' => ['Jam mulai dan jam selesai lembur wajib diisi.'],
            ]);
        }

        $startTimeStr = trim((string) $data['requested_start']);
        $endTimeStr   = trim((string) $data['requested_end']);

        $startCarbon = Carbon::parse($overtimeDate . ' ' . $startTimeStr);
        $endCarbon   = Carbon::parse($overtimeDate . ' ' . $endTimeStr);

        if ($endCarbon->lte($startCarbon)) {
            throw ValidationException::withMessages([
                'requested_end' => ['Jam selesai lembur harus lebih besar dari jam mulai.'],
            ]);
        }

        $requestedMinutes = (int) $startCarbon->diffInMinutes($endCarbon);
        if ($requestedMinutes <= 0) {
            throw ValidationException::withMessages([
                'requested_minutes' => ['Durasi lembur harus lebih dari 0 menit.'],
            ]);
        }

        // 5. Overlap validation: check for conflicting active overtime requests on the same date
        $conflicting = OvertimeRequest::where('employee_id', $employee->id)
            ->whereDate('overtime_date', $overtimeDate)
            ->whereIn('status', [OvertimeRequest::STATUS_SUBMITTED, OvertimeRequest::STATUS_APPROVED])
            ->where(function ($q) use ($startTimeStr, $endTimeStr) {
                $q->where(function ($sub) use ($startTimeStr, $endTimeStr) {
                    $sub->where('requested_start', '<', $endTimeStr)
                        ->where('requested_end', '>', $startTimeStr);
                });
            })
            ->first();

        if ($conflicting) {
            throw ValidationException::withMessages([
                'overtime_date' => ['Terdapat pengajuan lembur yang bertabrakan waktu pada tanggal tersebut.'],
            ]);
        }

        // 6. Reason validation
        $reason = trim((string) ($data['reason'] ?? ''));
        if (empty($reason)) {
            throw ValidationException::withMessages([
                'reason' => ['Alasan lembur wajib diisi.'],
            ]);
        }

        // 7. Find existing Attendance record on this date (if already created)
        $attendance = Attendance::where('employee_id', $employee->id)
            ->whereDate('attendance_date', $overtimeDate)
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
            $safeFilename = 'ot_' . bin2hex(random_bytes(16)) . '.' . $ext;
            $attachmentPath = $attachment->storeAs('overtime_attachments', $safeFilename, 'local');
        }

        $overtime = OvertimeRequest::create([
            'employee_id'            => $employee->id,
            'attendance_id'          => $attendance?->id,
            'overtime_date'          => $overtimeDate,
            'requested_start'        => $startTimeStr,
            'requested_end'          => $endTimeStr,
            'requested_minutes'      => $requestedMinutes,
            'approved_minutes'       => 0, // Unapproved overtime = 0 approved minutes
            'reason'                 => $reason,
            'project_task_reference' => $data['project_task_reference'] ?? null,
            'attachment_path'        => $attachmentPath,
            'status'                 => OvertimeRequest::STATUS_SUBMITTED,
            'submitted_at'           => now(),
            'created_by'             => $actor->id,
        ]);

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'OVERTIME_REQUEST_CREATED',
            'subject_type' => 'OvertimeRequest',
            'subject_id'   => $overtime->id,
            'description'  => "Pengajuan lembur #{$overtime->id} tanggal {$overtimeDate} ({$requestedMinutes} menit) diajukan oleh {$actor->name}",
            'timestamp'    => now(),
        ]);

        return $overtime->load(['employee', 'attendance']);
    }

    /**
     * Approve an OvertimeRequest.
     */
    public function approveRequest(Admin $actor, OvertimeRequest $overtime, ?int $approvedMinutes = null): OvertimeRequest
    {
        $this->authorizeApprovalAction($actor, $overtime);

        // Anti Self-Approval
        if ((int) $actor->employee_id === (int) $overtime->employee_id) {
            throw ValidationException::withMessages([
                'approval' => ['Dilarang menyetujui pengajuan lembur diri sendiri.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $overtime, $approvedMinutes) {
            $locked = OvertimeRequest::where('id', $overtime->id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== OvertimeRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ["Pengajuan lembur dengan status '{$locked?->status}' tidak dapat disetujui."],
                ]);
            }

            // Calculation rules: approved minutes cannot exceed requested minutes
            $finalApprovedMinutes = $approvedMinutes !== null ? (int) $approvedMinutes : (int) $locked->requested_minutes;

            if ($finalApprovedMinutes <= 0) {
                throw ValidationException::withMessages([
                    'approved_minutes' => ['Menit lembur yang disetujui harus lebih dari 0.'],
                ]);
            }

            if ($finalApprovedMinutes > (int) $locked->requested_minutes) {
                throw ValidationException::withMessages([
                    'approved_minutes' => ['Menit lembur yang disetujui (' . $finalApprovedMinutes . ' mnt) tidak boleh melebihi durasi yang diajukan (' . $locked->requested_minutes . ' mnt).'],
                ]);
            }

            $locked->status           = OvertimeRequest::STATUS_APPROVED;
            $locked->approved_minutes = $finalApprovedMinutes;
            $locked->approved_by      = $actor->id;
            $locked->approved_at      = now();
            $locked->save();

            // Integrate with Attendance Core
            $this->attendanceProcessor->applyApprovedOvertime($locked);

            ActivityLog::create([
                'admin_id'     => $actor->id,
                'action'       => 'OVERTIME_REQUEST_APPROVED',
                'subject_type' => 'OvertimeRequest',
                'subject_id'   => $locked->id,
                'description'  => "Pengajuan lembur #{$locked->id} tanggal {$locked->overtime_date} ({$finalApprovedMinutes} menit disetujui) oleh {$actor->name}",
                'timestamp'    => now(),
            ]);

            return $locked->fresh(['employee', 'attendance', 'approvedBy']);
        });
    }

    /**
     * Reject an OvertimeRequest.
     */
    public function rejectRequest(Admin $actor, OvertimeRequest $overtime, string $reason): OvertimeRequest
    {
        $this->authorizeApprovalAction($actor, $overtime);

        if ((int) $actor->employee_id === (int) $overtime->employee_id) {
            throw ValidationException::withMessages([
                'approval' => ['Dilarang menolak pengajuan lembur diri sendiri.'],
            ]);
        }

        if (empty(trim($reason))) {
            throw ValidationException::withMessages([
                'rejection_reason' => ['Alasan penolakan wajib diisi.'],
            ]);
        }

        return DB::transaction(function () use ($actor, $overtime, $reason) {
            $locked = OvertimeRequest::where('id', $overtime->id)->lockForUpdate()->first();

            if (!$locked || $locked->status !== OvertimeRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ["Pengajuan lembur dengan status '{$locked?->status}' tidak dapat ditolak."],
                ]);
            }

            $locked->status           = OvertimeRequest::STATUS_REJECTED;
            $locked->rejected_by      = $actor->id;
            $locked->rejected_at      = now();
            $locked->rejection_reason = trim($reason);
            $locked->save();

            ActivityLog::create([
                'admin_id'     => $actor->id,
                'action'       => 'OVERTIME_REQUEST_REJECTED',
                'subject_type' => 'OvertimeRequest',
                'subject_id'   => $locked->id,
                'description'  => "Pengajuan lembur #{$locked->id} tanggal {$locked->overtime_date} ditolak oleh {$actor->name}: " . trim($reason),
                'timestamp'    => now(),
            ]);

            return $locked->fresh(['employee', 'attendance', 'rejectedBy']);
        });
    }

    /**
     * Cancel an OvertimeRequest.
     */
    public function cancelRequest(Admin $actor, OvertimeRequest $overtime): OvertimeRequest
    {
        $role = strtolower((string) $actor->role);

        $isOwner = (int) $actor->employee_id === (int) $overtime->employee_id;
        $isHrOrSuper = $actor->isSuperAdmin() || in_array($role, ['hrd'], true);

        if (!$isOwner && !$isHrOrSuper) {
            throw ValidationException::withMessages([
                'authorization' => ['Anda tidak memiliki izin untuk membatalkan pengajuan lembur ini.'],
            ]);
        }

        if ($overtime->status !== OvertimeRequest::STATUS_SUBMITTED) {
            throw ValidationException::withMessages([
                'status' => ["Pengajuan lembur dengan status '{$overtime->status}' tidak dapat dibatalkan."],
            ]);
        }

        $overtime->update([
            'status'     => OvertimeRequest::STATUS_CANCELLED,
            'updated_by' => $actor->id,
        ]);

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'OVERTIME_REQUEST_CANCELLED',
            'subject_type' => 'OvertimeRequest',
            'subject_id'   => $overtime->id,
            'description'  => "Pengajuan lembur #{$overtime->id} dibatalkan oleh {$actor->name}",
            'timestamp'    => now(),
        ]);

        return $overtime->fresh(['employee', 'attendance']);
    }

    /**
     * Authorize approval / rejection scope.
     */
    protected function authorizeApprovalAction(Admin $actor, OvertimeRequest $overtime): void
    {
        $role = strtolower((string) $actor->role);

        // Technical roles and Building Admin are strictly denied
        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            throw ValidationException::withMessages([
                'authorization' => ['Peran Anda tidak memiliki wewenang untuk menyetujui/menolak pengajuan lembur.'],
            ]);
        }

        if ($actor->isSuperAdmin() || in_array($role, ['hrd', 'management'], true)) {
            return;
        }

        if ($role === 'supervisor') {
            $supervisorEmpId = $actor->employee_id ?? $actor->id;
            $employeeSupervisorId = $overtime->employee?->supervisor_id;
            if ((int) $employeeSupervisorId !== (int) $supervisorEmpId) {
                throw ValidationException::withMessages([
                    'authorization' => ['Supervisor hanya berwenang menyetujui lembur anggota tim langsung.'],
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
    public function downloadAttachment(Admin $actor, OvertimeRequest $overtime): BinaryFileResponse
    {
        $role = strtolower((string) $actor->role);

        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            abort(403, 'Akses dokumen lampiran ditolak untuk peran Anda.');
        }

        $isOwner = (int) $actor->employee_id === (int) $overtime->employee_id;
        $isSuperOrHr = $actor->isSuperAdmin() || in_array($role, ['hrd', 'management'], true);
        $isSupervisor = $role === 'supervisor' && (int) $overtime->employee?->supervisor_id === (int) ($actor->employee_id ?? $actor->id);

        if (!$isOwner && !$isSuperOrHr && !$isSupervisor) {
            abort(403, 'Akses dokumen lampiran lembur tidak diizinkan.');
        }

        if (!$overtime->attachment_path || !Storage::disk('local')->exists($overtime->attachment_path)) {
            abort(404, 'Dokumen lampiran tidak ditemukan.');
        }

        $realPath = Storage::disk('local')->path($overtime->attachment_path);
        $expectedDir = realpath(Storage::disk('local')->path('overtime_attachments'));
        if ($expectedDir && !str_starts_with(realpath($realPath) ?: '', $expectedDir)) {
            abort(403, 'Akses path tidak valid.');
        }

        ActivityLog::create([
            'admin_id'     => $actor->id,
            'action'       => 'OVERTIME_DOCUMENT_ACCESSED',
            'subject_type' => 'OvertimeRequest',
            'subject_id'   => $overtime->id,
            'description'  => "Dokumen lampiran lembur #{$overtime->id} diakses oleh {$actor->name}",
            'timestamp'    => now(),
        ]);

        return response()->download($realPath, basename($overtime->attachment_path));
    }
}
