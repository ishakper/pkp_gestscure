<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Contract;
use App\Models\DocumentAcknowledgement;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Internship;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OnboardingService
{
    /**
     * Create Onboarding Case with standard checklist tasks
     */
    public function createCase(array $data, ?Admin $admin = null): OnboardingCase
    {
        return DB::transaction(function () use ($data, $admin) {
            $year = Carbon::now()->format('Y');
            $count = OnboardingCase::whereYear('created_at', $year)->count() + 1;
            $caseNumber = sprintf('ONB-%s-%04d', $year, $count);

            $startDate = $data['start_date'] ?? Carbon::today()->toDateString();

            $case = OnboardingCase::create([
                'case_number' => $caseNumber,
                'employee_id' => $data['employee_id'] ?? null,
                'internship_id' => $data['internship_id'] ?? null,
                'candidate_id' => $data['candidate_id'] ?? null,
                'status' => 'PENDING',
                'start_date' => $startDate,
                'target_completion_date' => $data['target_completion_date'] ?? Carbon::parse($startDate)->addWeeks(2)->toDateString(),
                'hr_owner_id' => $data['hr_owner_id'] ?? $admin?->id,
                'supervisor_id' => $data['supervisor_id'] ?? null,
                'division_id' => $data['division_id'] ?? null,
                'position_id' => $data['position_id'] ?? null,
                'employment_type' => $data['employment_type'] ?? 'PERMANENT',
                'work_location' => $data['work_location'] ?? 'Kantor Pusat PKP',
                'notes' => $data['notes'] ?? null,
            ]);

            // Seed standard onboarding checklist
            $standardTasks = [
                ['key' => 'identity_verification', 'title' => 'Verifikasi Berkas Identitas (KTP, KK, NPWP)', 'category' => 'HR_ADMIN', 'required' => true, 'order' => 1],
                ['key' => 'contract_signing', 'title' => 'Penandatanganan Perjanjian Kerja / Kontrak', 'category' => 'HR_ADMIN', 'required' => true, 'order' => 2],
                ['key' => 'nda_signing', 'title' => 'Penandatanganan NDA & Pakta Integritas Perusahaan', 'category' => 'COMPLIANCE', 'required' => true, 'order' => 3],
                ['key' => 'employee_account', 'title' => 'Pembuatan Akun Karyawan & Akses Portal SSO', 'category' => 'IT_ACCESS', 'required' => true, 'order' => 4],
                ['key' => 'company_email', 'title' => 'Pembuatan Email Resmi Perusahaan (@pkp.co.id)', 'category' => 'IT_ACCESS', 'required' => false, 'order' => 5],
                ['key' => 'building_access_request', 'title' => 'Pengajuan Hak Akses Pintu & Ruangan SecureGate', 'category' => 'FACILITY', 'required' => true, 'order' => 6],
                ['key' => 'biometric_enrollment', 'title' => 'Registrasi Kartu RFID & Biometrik Wajah/Sidik Jari', 'category' => 'FACILITY', 'required' => true, 'order' => 7],
                ['key' => 'asset_assignment', 'title' => 'Penyerahan Perangkat Kerja & Inventaris Perusahaan', 'category' => 'FACILITY', 'required' => false, 'order' => 8],
                ['key' => 'orientation', 'title' => 'Sesi Orientasi Budaya Kerja & Pengenalan Tim Divisi', 'category' => 'ORIENTATION', 'required' => true, 'order' => 9],
                ['key' => 'policy_acknowledgement', 'title' => 'Pernyataan Pemahaman & Persetujuan Kebijakan HR', 'category' => 'COMPLIANCE', 'required' => true, 'order' => 10],
            ];

            foreach ($standardTasks as $t) {
                OnboardingTask::create([
                    'onboarding_case_id' => $case->id,
                    'task_key' => $t['key'],
                    'title' => $t['title'],
                    'category' => $t['category'],
                    'status' => 'PENDING',
                    'is_required' => $t['required'],
                    'order_index' => $t['order'],
                    'due_date' => Carbon::parse($startDate)->addDays($t['order'])->toDateString(),
                ]);
            }

            $this->logActivity('onboarding_case_created', $case, [
                'case_number' => $case->case_number,
                'employee_id' => $case->employee_id,
                'internship_id' => $case->internship_id,
                'employment_type' => $case->employment_type,
            ], $admin);

            return $case->load(['tasks', 'employee', 'internship', 'supervisor', 'division', 'position']);
        });
    }

    /**
     * Update an onboarding task status & blocker reason
     */
    public function updateTask(int $taskId, array $data, ?Admin $admin = null): OnboardingTask
    {
        return DB::transaction(function () use ($taskId, $data, $admin) {
            $task = OnboardingTask::findOrFail($taskId);
            $oldStatus = $task->status;
            $newStatus = strtoupper($data['status'] ?? $task->status);

            $updateData = [
                'status' => $newStatus,
                'notes' => $data['notes'] ?? $task->notes,
                'blocker_reason' => $newStatus === 'BLOCKED' ? ($data['blocker_reason'] ?? 'Terkendala proses administrasi') : null,
            ];

            if ($newStatus === 'COMPLETED') {
                $updateData['completed_at'] = Carbon::now();
                $updateData['completed_by'] = $admin?->id;
            } elseif ($newStatus !== 'COMPLETED' && $oldStatus === 'COMPLETED') {
                $updateData['completed_at'] = null;
                $updateData['completed_by'] = null;
            }

            $task->update($updateData);

            // Recompute Onboarding Case status based on tasks
            $case = $task->onboardingCase;
            if ($case->status !== 'COMPLETED' && $case->status !== 'CANCELLED') {
                $hasBlocked = $case->tasks()->where('status', 'BLOCKED')->exists();
                $hasActive = $case->tasks()->whereIn('status', ['IN_PROGRESS', 'COMPLETED'])->exists();

                if ($hasBlocked) {
                    $case->update(['status' => 'BLOCKED']);
                } elseif ($hasActive) {
                    $case->update(['status' => 'IN_PROGRESS']);
                } else {
                    $case->update(['status' => 'PENDING']);
                }
            }

            $this->logActivity('onboarding_task_updated', $task, [
                'case_id' => $case->id,
                'task_key' => $task->task_key,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'blocker_reason' => $task->blocker_reason,
            ], $admin);

            return $task;
        });
    }

    /**
     * Complete an Onboarding Case (Completion Gate)
     */
    public function completeCase(int $caseId, ?Admin $admin = null): OnboardingCase
    {
        return DB::transaction(function () use ($caseId, $admin) {
            $case = OnboardingCase::with('tasks')->findOrFail($caseId);

            // Rule 1: Cannot complete if any task is BLOCKED
            $blockedTasks = $case->tasks->where('status', 'BLOCKED');
            if ($blockedTasks->isNotEmpty()) {
                $blockedTitles = $blockedTasks->pluck('title')->implode(', ');
                throw ValidationException::withMessages([
                    'case' => ["Proses onboarding tidak dapat diselesaikan karena terdapat tugas yang terblokir (blocked): {$blockedTitles}"],
                ]);
            }

            // Rule 2: All required tasks must be COMPLETED or NOT_REQUIRED
            $incompleteRequired = $case->tasks
                ->where('is_required', true)
                ->whereNotIn('status', ['COMPLETED', 'NOT_REQUIRED']);

            if ($incompleteRequired->isNotEmpty()) {
                $missingTitles = $incompleteRequired->pluck('title')->implode(', ');
                throw ValidationException::withMessages([
                    'case' => ["Semua tugas wajib (checklist) harus diselesaikan terlebih dahulu: {$missingTitles}"],
                ]);
            }

            $case->update([
                'status' => 'COMPLETED',
                'completed_at' => Carbon::now(),
                'completed_by' => $admin?->id,
            ]);

            $this->logActivity('onboarding_completed', $case, [
                'case_number' => $case->case_number,
                'employee_id' => $case->employee_id,
                'internship_id' => $case->internship_id,
                'completed_at' => $case->completed_at->toIso8601String(),
            ], $admin);

            return $case->fresh(['tasks', 'employee', 'internship', 'completedByAdmin']);
        });
    }

    /**
     * Contract Management
     */
    public function createContract(array $data, ?Admin $admin = null): Contract
    {
        return DB::transaction(function () use ($data, $admin) {
            $year = Carbon::now()->format('Y');
            $count = Contract::whereYear('created_at', $year)->count() + 1;
            $contractNumber = sprintf('CTR-%s-%04d', $year, $count);

            $contract = Contract::create([
                'contract_number' => $data['contract_number'] ?? $contractNumber,
                'employee_id' => $data['employee_id'] ?? null,
                'internship_id' => $data['internship_id'] ?? null,
                'contract_type' => $data['contract_type'] ?? 'FIXED_TERM',
                'title' => $data['title'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'effective_date' => $data['effective_date'] ?? $data['start_date'],
                'status' => $data['status'] ?? 'DRAFT',
                'signed_date' => $data['signed_date'] ?? null,
                'signed_by_employee' => $data['signed_by_employee'] ?? null,
                'signed_by_company' => $data['signed_by_company'] ?? 'Direktur HR & Operasional',
                'renewal_status' => $data['renewal_status'] ?? 'NONE',
                'notes' => $data['notes'] ?? null,
            ]);

            $this->logActivity('contract_created', $contract, [
                'contract_number' => $contract->contract_number,
                'type' => $contract->contract_type,
                'status' => $contract->status,
            ], $admin);

            return $contract->load(['employee', 'internship']);
        });
    }

    public function updateContract(int $contractId, array $data, ?Admin $admin = null): Contract
    {
        return DB::transaction(function () use ($contractId, $data, $admin) {
            $contract = Contract::findOrFail($contractId);
            $oldStatus = $contract->status;

            $contract->update(array_filter([
                'title' => $data['title'] ?? $contract->title,
                'start_date' => $data['start_date'] ?? $contract->start_date,
                'end_date' => array_key_exists('end_date', $data) ? $data['end_date'] : $contract->end_date,
                'effective_date' => $data['effective_date'] ?? $contract->effective_date,
                'status' => $data['status'] ?? $contract->status,
                'signed_date' => $data['signed_date'] ?? $contract->signed_date,
                'signed_by_employee' => $data['signed_by_employee'] ?? $contract->signed_by_employee,
                'signed_by_company' => $data['signed_by_company'] ?? $contract->signed_by_company,
                'renewal_status' => $data['renewal_status'] ?? $contract->renewal_status,
                'notes' => $data['notes'] ?? $contract->notes,
            ]));

            $this->logActivity('contract_updated', $contract, [
                'contract_number' => $contract->contract_number,
                'old_status' => $oldStatus,
                'new_status' => $contract->status,
            ], $admin);

            return $contract;
        });
    }

    /**
     * Document Management (Private, Secure, Encrypted & Authorized)
     */
    public function uploadDocument(UploadedFile $file, array $data, ?Admin $admin = null): EmployeeDocument
    {
        return DB::transaction(function () use ($file, $data, $admin) {
            // Validate allowed extensions and MIME types
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $extension = strtolower($file->getClientOriginalExtension());

            if (!in_array($extension, $allowedExtensions, true)) {
                throw ValidationException::withMessages([
                    'file' => ['Format berkas tidak diizinkan. Hanya menerima PDF, JPG, PNG, DOC, DOCX.'],
                ]);
            }

            $allowedMimes = [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ];

            $mimeType = $file->getMimeType();
            if (!in_array($mimeType, $allowedMimes, true)) {
                throw ValidationException::withMessages([
                    'file' => ['MIME type berkas tidak valid atau tidak diizinkan.'],
                ]);
            }

            // Max size 10MB
            if ($file->getSize() > 10485760) {
                throw ValidationException::withMessages([
                    'file' => ['Ukuran berkas melebihi batas maksimal 10MB.'],
                ]);
            }

            // Sanitize original file name
            $originalName = preg_replace('/[^a-zA-Z0-9_\.-]/', '_', $file->getClientOriginalName());

            // Checksum
            $checksum = hash_file('sha256', $file->getRealPath());

            // Storage in private disk 'local'
            $year = Carbon::now()->format('Y');
            $uuid = Str::uuid()->toString();
            $storedName = "{$uuid}.{$extension}";
            $relativePath = "hr_documents/{$year}/{$storedName}";

            Storage::disk('local')->putFileAs("hr_documents/{$year}", $file, $storedName);

            // Document numbering
            $count = EmployeeDocument::whereYear('created_at', $year)->count() + 1;
            $documentNumber = sprintf('DOC-%s-%04d', $year, $count);

            // Version handling
            $version = 1;
            $parentId = $data['parent_document_id'] ?? null;
            if ($parentId) {
                $parent = EmployeeDocument::find($parentId);
                if ($parent) {
                    $version = $parent->version + 1;
                    $parent->update(['status' => 'ARCHIVED']);
                }
            }

            $doc = EmployeeDocument::create([
                'document_number' => $documentNumber,
                'employee_id' => $data['employee_id'] ?? null,
                'internship_id' => $data['internship_id'] ?? null,
                'contract_id' => $data['contract_id'] ?? null,
                'category' => strtoupper($data['category'] ?? 'OTHER'),
                'title' => $data['title'] ?? pathinfo($originalName, PATHINFO_FILENAME),
                'description' => $data['description'] ?? null,
                'file_path' => $relativePath,
                'file_name' => $originalName,
                'mime_type' => $mimeType,
                'file_size' => $file->getSize(),
                'checksum' => $checksum,
                'version' => $version,
                'parent_document_id' => $parentId,
                'status' => $data['status'] ?? 'PENDING_VERIFICATION',
                'visibility' => $data['visibility'] ?? 'CONFIDENTIAL_HR',
                'issue_date' => $data['issue_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'issuer' => $data['issuer'] ?? null,
                'uploaded_by' => $admin?->id,
            ]);

            $this->logActivity('document_uploaded', $doc, [
                'document_number' => $doc->document_number,
                'category' => $doc->category,
                'file_name' => $doc->file_name,
                'version' => $doc->version,
            ], $admin);

            return $doc->load(['employee', 'internship', 'contract']);
        });
    }

    public function verifyDocument(int $documentId, string $status, ?string $notes = null, ?Admin $admin = null): EmployeeDocument
    {
        return DB::transaction(function () use ($documentId, $status, $notes, $admin) {
            $doc = EmployeeDocument::findOrFail($documentId);
            $newStatus = in_array(strtoupper($status), ['VERIFIED', 'REJECTED']) ? strtoupper($status) : 'VERIFIED';

            $doc->update([
                'status' => $newStatus,
                'verified_by' => $admin?->id,
                'verified_at' => Carbon::now(),
                'verification_notes' => $notes,
            ]);

            $this->logActivity('document_verified', $doc, [
                'document_number' => $doc->document_number,
                'status' => $newStatus,
                'notes' => $notes,
            ], $admin);

            return $doc;
        });
    }

    public function acknowledgeDocument(int $documentId, array $data, ?Admin $admin = null): DocumentAcknowledgement
    {
        return DB::transaction(function () use ($documentId, $data, $admin) {
            $doc = EmployeeDocument::findOrFail($documentId);

            $ack = DocumentAcknowledgement::create([
                'document_id' => $doc->id,
                'employee_id' => $data['employee_id'] ?? null,
                'internship_id' => $data['internship_id'] ?? null,
                'version' => $doc->version,
                'acknowledged_at' => Carbon::now(),
                'ip_address' => $data['ip_address'] ?? request()->ip(),
                'user_agent' => substr((string) ($data['user_agent'] ?? request()->userAgent()), 0, 255),
                'notes' => $data['notes'] ?? 'Menyetujui isi dan ketentuan dokumen.',
            ]);

            $this->logActivity('policy_acknowledged', $ack, [
                'document_id' => $doc->id,
                'document_number' => $doc->document_number,
                'employee_id' => $ack->employee_id,
                'version' => $ack->version,
            ], $admin);

            return $ack;
        });
    }

    /**
     * Check download authorization
     */
    public function authorizeDocumentAccess(EmployeeDocument $doc, Admin $admin): bool
    {
        $role = strtolower((string) $admin->role);

        // Super Admin & HRD have full access
        if ($admin->isSuperAdmin() || $role === 'hrd') {
            return true;
        }

        // Technical roles (Developer, DevOps, Infra, Security Engineer) & Building Admin strictly FORBIDDEN from private HR documents
        if (in_array($role, ['developer', 'devops', 'infra_admin', 'security_engineer', 'building_admin'], true)) {
            return false;
        }

        // Management role can view non-sensitive company documents
        if ($role === 'management') {
            return in_array($doc->category, ['CONTRACT', 'NDA', 'ASSIGNMENT', 'PERFORMANCE', 'INTERNSHIP', 'OTHER'], true);
        }

        // Supervisor role: can view documents of team members/direct reports for allowed categories (NO medical/NIK)
        if ($role === 'supervisor') {
            if (in_array($doc->category, ['MEDICAL', 'IDENTITY'], true)) {
                return false;
            }

            // Check if document belongs to supervisor's direct report or division
            if ($doc->employee_id) {
                $emp = Employee::find($doc->employee_id);
                if ($emp && ($emp->supervisor_id === ($admin->employee_id ?? $admin->id) || $emp->division_id === $admin->division_id)) {
                    return true;
                }
            }

            if ($doc->internship_id) {
                $intern = Internship::find($doc->internship_id);
                if ($intern && ($intern->mentor_id === ($admin->employee_id ?? $admin->id) || $intern->supervisor_id === ($admin->employee_id ?? $admin->id))) {
                    return true;
                }
            }

            return false;
        }

        // Employee / Intern self-service: only own documents that are EMPLOYEE_VISIBLE or uploaded by them
        if (in_array($role, ['employee', 'intern'], true)) {
            $isOwn = ($doc->employee_id && $doc->employee_id === $admin->employee_id) ||
                     ($doc->internship_id && $admin->internship_id && $doc->internship_id === $admin->internship_id);
            return $isOwn && in_array($doc->visibility, ['EMPLOYEE_VISIBLE', 'PUBLIC_INTERNAL'], true);
        }

        return false;
    }

    /**
     * Metrics for Onboarding Dashboard
     */
    public function getMetrics(): array
    {
        return [
            'active_onboardings' => OnboardingCase::whereIn('status', ['PENDING', 'IN_PROGRESS'])->count(),
            'completed_onboardings' => OnboardingCase::where('status', 'COMPLETED')->count(),
            'blocked_tasks' => OnboardingTask::where('status', 'BLOCKED')->count(),
            'active_contracts' => Contract::where('status', 'ACTIVE')->count(),
            'expiring_contracts' => Contract::where('status', 'ACTIVE')
                ->whereNotNull('end_date')
                ->whereBetween('end_date', [Carbon::today(), Carbon::today()->addDays(30)])
                ->count(),
            'pending_documents' => EmployeeDocument::where('status', 'PENDING_VERIFICATION')->count(),
            'total_documents' => EmployeeDocument::count(),
        ];
    }

    private function logActivity(string $action, $model, array $meta = [], ?Admin $admin = null): void
    {
        ActivityLog::create([
            'admin_id' => $admin?->id,
            'action' => $action,
            'subject_type' => get_class($model),
            'subject_id' => $model->id ?? null,
            'description' => "Aktivitas Onboarding: {$action}",
            'timestamp' => Carbon::now(),
        ]);
    }
}
