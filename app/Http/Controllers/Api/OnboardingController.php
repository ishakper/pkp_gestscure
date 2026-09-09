<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Internship;
use App\Models\OnboardingCase;
use App\Models\OnboardingTask;
use App\Policies\OnboardingPolicy;
use App\Services\OnboardingService;
use App\Services\PortalAccess;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OnboardingController extends Controller
{
    protected OnboardingService $service;
    protected OnboardingPolicy $policy;
    protected PortalAccess $portalAccess;

    public function __construct(OnboardingService $service, OnboardingPolicy $policy, PortalAccess $portalAccess)
    {
        $this->service = $service;
        $this->policy = $policy;
        $this->portalAccess = $portalAccess;
    }

    /**
     * Dashboard Metrics
     */
    public function metrics(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$this->policy->viewAny($admin)) {
            return response()->json(['message' => 'Unauthorized to view onboarding data.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->getMetrics(),
        ]);
    }

    /**
     * List Onboarding Cases
     */
    public function cases(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$this->policy->viewAny($admin)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $query = OnboardingCase::with(['employee', 'internship', 'supervisor', 'division', 'position', 'tasks']);

        // Scoping for Supervisor
        if (strtolower((string) $admin->role) === 'supervisor') {
            $supervisorEmpId = $admin->employee_id ?? $admin->id;
            $query->where(function ($q) use ($supervisorEmpId, $admin) {
                $q->where('supervisor_id', $supervisorEmpId)
                  ->orWhere('division_id', $admin->division_id);
            });
        } elseif (in_array(strtolower((string) $admin->role), ['employee', 'intern'], true)) {
            $query->where(function ($q) use ($admin) {
                if ($admin->employee_id) $q->where('employee_id', $admin->employee_id);
                if ($admin->internship_id) $q->orWhere('internship_id', $admin->internship_id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->status));
        }

        if ($request->filled('employment_type')) {
            $query->where('employment_type', strtoupper($request->employment_type));
        }

        if ($request->filled('division_id')) {
            $query->where('division_id', $request->division_id);
        }

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('case_number', 'like', $s)
                  ->orWhereHas('employee', fn($eq) => $eq->where('name', 'like', $s)->orWhere('employee_id', 'like', $s))
                  ->orWhereHas('internship', fn($iq) => $iq->where('intern_name', 'like', $s)->orWhere('intern_id', 'like', $s));
            });
        }

        $cases = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $cases->items(),
            'pagination' => [
                'total' => $cases->total(),
                'per_page' => $cases->perPage(),
                'current_page' => $cases->currentPage(),
                'last_page' => $cases->lastPage(),
            ],
        ]);
    }

    /**
     * Create Onboarding Case
     */
    public function storeCase(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$this->policy->manage($admin)) {
            return response()->json(['message' => 'Hanya HRD atau Super Admin yang dapat membuat kasus onboarding.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'internship_id' => 'nullable|exists:internships,id',
            'candidate_id' => 'nullable|exists:candidates,id',
            'start_date' => 'required|date',
            'target_completion_date' => 'nullable|date|after_or_equal:start_date',
            'supervisor_id' => 'nullable|exists:employees,id',
            'division_id' => 'nullable|exists:divisions,id',
            'position_id' => 'nullable|exists:positions,id',
            'employment_type' => 'required|string|in:PERMANENT,FIXED_TERM,PROBATION,INTERNSHIP',
            'work_location' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        $case = $this->service->createCase($validated, $admin);

        return response()->json([
            'success' => true,
            'message' => 'Kasus onboarding berhasil dibuat bersama 10 tugas checklist standar.',
            'data' => $case,
        ], 201);
    }

    /**
     * Show Onboarding Case Detail
     */
    public function showCase(Request $request, int $id): JsonResponse
    {
        $case = OnboardingCase::with(['employee', 'internship', 'candidate', 'supervisor', 'division', 'position', 'tasks.completedByAdmin'])->findOrFail($id);

        if (!$this->policy->view($request->user(), $case)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $case,
            'progress' => $case->calculateProgress(),
        ]);
    }

    /**
     * Update Onboarding Task
     */
    public function updateTask(Request $request, int $taskId): JsonResponse
    {
        $task = OnboardingTask::with('onboardingCase')->findOrFail($taskId);

        if (!$this->policy->updateTask($request->user(), $task->onboardingCase)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:PENDING,IN_PROGRESS,COMPLETED,BLOCKED,NOT_REQUIRED',
            'notes' => 'nullable|string',
            'blocker_reason' => 'nullable|string',
        ]);

        $updatedTask = $this->service->updateTask($taskId, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Tugas onboarding berhasil diperbarui.',
            'data' => $updatedTask,
            'case_status' => $task->onboardingCase->fresh()->status,
        ]);
    }

    /**
     * Complete Onboarding Case
     */
    public function completeCase(Request $request, int $id): JsonResponse
    {
        $case = OnboardingCase::findOrFail($id);

        if (!$this->policy->manage($request->user())) {
            return response()->json(['message' => 'Hanya HRD yang berwenang menyelesaikan kasus onboarding.'], 403);
        }

        $completedCase = $this->service->completeCase($id, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Selamat! Kasus onboarding telah resmi diselesaikan.',
            'data' => $completedCase,
        ]);
    }

    /**
     * List Contracts
     */
    public function contracts(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$this->portalAccess->can($admin, 'contract.view')) {
            return response()->json(['message' => 'Akses kontrak kerja ditolak.'], 403);
        }

        $query = Contract::with(['employee', 'internship']);

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->status));
        }

        if ($request->filled('contract_type')) {
            $query->where('contract_type', strtoupper($request->contract_type));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->boolean('expiring')) {
            $days = (int) $request->input('days', 30);
            $query->where('status', 'ACTIVE')
                  ->whereNotNull('end_date')
                  ->whereBetween('end_date', [Carbon::today(), Carbon::today()->addDays($days)]);
        }

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('contract_number', 'like', $s)
                  ->orWhere('title', 'like', $s)
                  ->orWhereHas('employee', fn($eq) => $eq->where('name', 'like', $s)->orWhere('employee_id', 'like', $s));
            });
        }

        $contracts = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $contracts->items(),
            'pagination' => [
                'total' => $contracts->total(),
                'per_page' => $contracts->perPage(),
                'current_page' => $contracts->currentPage(),
                'last_page' => $contracts->lastPage(),
            ],
        ]);
    }

    /**
     * Create Contract
     */
    public function storeContract(Request $request): JsonResponse
    {
        if (!$this->policy->manageContract($request->user())) {
            return response()->json(['message' => 'Hanya HRD yang dapat mengelola kontrak kerja.'], 403);
        }

        $validated = $request->validate([
            'contract_number' => 'nullable|string|unique:contracts,contract_number',
            'employee_id' => 'nullable|exists:employees,id',
            'internship_id' => 'nullable|exists:internships,id',
            'contract_type' => 'required|in:PERMANENT,FIXED_TERM,PROBATION,INTERNSHIP,FREELANCE,NDA,OTHER',
            'title' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'effective_date' => 'nullable|date',
            'status' => 'nullable|in:DRAFT,PENDING_SIGNATURE,ACTIVE,EXPIRED,TERMINATED,RENEWED,CANCELLED',
            'signed_date' => 'nullable|date',
            'signed_by_employee' => 'nullable|string|max:100',
            'signed_by_company' => 'nullable|string|max:100',
            'renewal_status' => 'nullable|in:NONE,ELIGIBLE,RENEWED,NOT_RENEWED',
            'notes' => 'nullable|string',
        ]);

        $contract = $this->service->createContract($validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Kontrak kerja berhasil dibuat.',
            'data' => $contract,
        ], 201);
    }

    /**
     * Update Contract
     */
    public function updateContract(Request $request, int $id): JsonResponse
    {
        if (!$this->policy->manageContract($request->user())) {
            return response()->json(['message' => 'Hanya HRD yang dapat mengubah kontrak kerja.'], 403);
        }

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date',
            'effective_date' => 'nullable|date',
            'status' => 'sometimes|in:DRAFT,PENDING_SIGNATURE,ACTIVE,EXPIRED,TERMINATED,RENEWED,CANCELLED',
            'signed_date' => 'nullable|date',
            'signed_by_employee' => 'nullable|string|max:100',
            'signed_by_company' => 'nullable|string|max:100',
            'renewal_status' => 'nullable|in:NONE,ELIGIBLE,RENEWED,NOT_RENEWED',
            'notes' => 'nullable|string',
        ]);

        $contract = $this->service->updateContract($id, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Kontrak kerja berhasil diperbarui.',
            'data' => $contract,
        ]);
    }

    /**
     * List Secure Documents
     */
    public function documents(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$this->portalAccess->can($admin, 'document.view')) {
            return response()->json(['message' => 'Akses dokumen ditolak.'], 403);
        }

        $query = EmployeeDocument::with(['employee', 'internship', 'contract', 'uploadedByAdmin', 'verifiedByAdmin']);

        // Scope check for Supervisor
        if (strtolower((string) $admin->role) === 'supervisor') {
            // Cannot view confidential medical or identity documents
            $query->whereNotIn('category', ['MEDICAL', 'IDENTITY']);
            $supervisorEmpId = $admin->employee_id ?? $admin->id;
            $query->where(function ($q) use ($supervisorEmpId, $admin) {
                $q->whereHas('employee', fn($eq) => $eq->where('supervisor_id', $supervisorEmpId)->orWhere('division_id', $admin->division_id))
                  ->orWhereHas('internship', fn($iq) => $iq->where('mentor_id', $supervisorEmpId)->orWhere('supervisor_id', $supervisorEmpId));
            });
        } elseif (in_array(strtolower((string) $admin->role), ['employee', 'intern'], true)) {
            $query->where(function ($q) use ($admin) {
                if ($admin->employee_id) $q->where('employee_id', $admin->employee_id);
                if ($admin->internship_id) $q->orWhere('internship_id', $admin->internship_id);
            })->whereIn('visibility', ['EMPLOYEE_VISIBLE', 'PUBLIC_INTERNAL']);
        } elseif (strtolower((string) $admin->role) === 'management') {
            $query->whereIn('category', ['CONTRACT', 'NDA', 'ASSIGNMENT', 'PERFORMANCE', 'INTERNSHIP', 'OTHER']);
        }

        if ($request->filled('category')) {
            $query->where('category', strtoupper($request->category));
        }

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->status));
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('internship_id')) {
            $query->where('internship_id', $request->internship_id);
        }

        if ($request->filled('search')) {
            $s = '%' . $request->search . '%';
            $query->where(function ($q) use ($s) {
                $q->where('document_number', 'like', $s)
                  ->orWhere('title', 'like', $s)
                  ->orWhere('file_name', 'like', $s)
                  ->orWhereHas('employee', fn($eq) => $eq->where('name', 'like', $s)->orWhere('employee_id', 'like', $s));
            });
        }

        $docs = $query->orderBy('created_at', 'desc')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data' => $docs->items(),
            'pagination' => [
                'total' => $docs->total(),
                'per_page' => $docs->perPage(),
                'current_page' => $docs->currentPage(),
                'last_page' => $docs->lastPage(),
            ],
        ]);
    }

    /**
     * Upload Private Document
     */
    public function uploadDocument(Request $request): JsonResponse
    {
        if (!$this->policy->manageDocument($request->user())) {
            return response()->json(['message' => 'Hanya HRD yang berwenang mengunggah berkas dokumen resmi.'], 403);
        }

        $request->validate([
            'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png,doc,docx',
            'category' => 'required|in:IDENTITY,CONTRACT,NDA,EDUCATION,CERTIFICATION,ASSIGNMENT,TRAINING,MEDICAL,PERFORMANCE,INTERNSHIP,OTHER',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'employee_id' => 'nullable|exists:employees,id',
            'internship_id' => 'nullable|exists:internships,id',
            'contract_id' => 'nullable|exists:contracts,id',
            'parent_document_id' => 'nullable|exists:employee_documents,id',
            'visibility' => 'nullable|in:CONFIDENTIAL_HR,SUPERVISOR_SHARED,EMPLOYEE_VISIBLE,PUBLIC_INTERNAL',
            'issue_date' => 'nullable|date',
            'expiry_date' => 'nullable|date',
            'issuer' => 'nullable|string|max:255',
        ]);

        $file = $request->file('file');
        $doc = $this->service->uploadDocument($file, $request->all(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Dokumen berhasil diunggah ke private storage dan dicatat.',
            'data' => $doc,
        ], 201);
    }

    /**
     * Verify Document
     */
    public function verifyDocument(Request $request, int $id): JsonResponse
    {
        if (!$this->portalAccess->can($request->user(), 'document.verify')) {
            return response()->json(['message' => 'Hanya HRD yang berwenang memverifikasi dokumen.'], 403);
        }

        $validated = $request->validate([
            'status' => 'required|in:VERIFIED,REJECTED',
            'notes' => 'nullable|string',
        ]);

        $doc = $this->service->verifyDocument($id, $validated['status'], $validated['notes'] ?? null, $request->user());

        return response()->json([
            'success' => true,
            'message' => "Dokumen berhasil diupdate statusnya menjadi {$validated['status']}.",
            'data' => $doc,
        ]);
    }

    /**
     * Secure Download Document
     */
    public function downloadDocument(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $doc = EmployeeDocument::findOrFail($id);

        if (!$this->policy->viewDocument($request->user(), $doc)) {
            return response()->json(['message' => 'Akses dokumen ditolak. Anda tidak memiliki otorisasi untuk membaca berkas ini.'], 403);
        }

        $disk = Storage::disk('local');
        if (!$disk->exists($doc->file_path)) {
            return response()->json(['message' => 'Berkas fisik dokumen tidak ditemukan di server.'], 404);
        }

        $absolutePath = $disk->path($doc->file_path);

        // Security check: ensure path is within storage directory to prevent path traversal
        $diskRoot = realpath($disk->path('')) ?: $disk->path('');
        $realFile = realpath($absolutePath) ?: $absolutePath;
        $normDiskRoot = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $diskRoot), DIRECTORY_SEPARATOR);
        $normRealFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $realFile);

        if (!str_starts_with($normRealFile, $normDiskRoot)) {
            return response()->json(['message' => 'Invalid file path traversal detected.'], 400);
        }

        return response()->download($absolutePath, $doc->file_name, [
            'Content-Type' => $doc->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Acknowledge Document
     */
    public function acknowledgeDocument(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'internship_id' => 'nullable|exists:internships,id',
            'notes' => 'nullable|string',
        ]);

        $ack = $this->service->acknowledgeDocument($id, $validated, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Persetujuan / acknowledgement dokumen berhasil dicatat.',
            'data' => $ack,
        ]);
    }
}
