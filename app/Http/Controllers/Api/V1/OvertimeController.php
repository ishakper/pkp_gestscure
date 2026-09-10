<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OvertimeRequest;
use App\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OvertimeController extends Controller
{
    public function __construct(
        protected OvertimeService $service
    ) {}

    /**
     * List overtime requests with scoping and filters.
     */
    public function index(Request $request): JsonResponse
    {
        $actor = Auth::user();
        $role = strtolower((string) $actor->role);

        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Akses ditolak untuk peran {$role}.",
            ], 403);
        }

        $query = OvertimeRequest::with([
            'employee:id,name,employee_id,division_id,position_id,supervisor_id',
            'attendance:id,attendance_date,status,clock_in_at,clock_out_at,overtime_minutes',
            'approvedBy:id,name,role',
            'rejectedBy:id,name,role',
        ]);

        // Scoping
        if (in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $actor->employee_id);
        } elseif ($role === 'supervisor') {
            $supervisorEmpId = $actor->employee_id ?? $actor->id;
            $query->whereHas('employee', function ($q) use ($supervisorEmpId) {
                $q->where('supervisor_id', $supervisorEmpId);
            });
        }

        // Filters
        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }
        if ($request->filled('employee_id') && !in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('overtime_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('overtime_date', '<=', $request->query('to_date'));
        }

        $requests = $query->orderByDesc('created_at')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $requests->items(),
            'meta'    => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'per_page'     => $requests->perPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    /**
     * Submit a new overtime request.
     */
    public function store(Request $request): JsonResponse
    {
        $actor = Auth::user();
        $role = strtolower((string) $actor->role);

        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Akses ditolak untuk peran {$role}.",
            ], 403);
        }

        $validated = $request->validate([
            'overtime_date'          => 'required|date',
            'requested_start'        => 'required|string|max:10',
            'requested_end'          => 'required|string|max:10',
            'reason'                 => 'required|string|min:3|max:1000',
            'project_task_reference' => 'nullable|string|max:255',
            'employee_id'            => 'nullable|integer|exists:employees,id',
            'attachment'             => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        $created = $this->service->createRequest($actor, $validated, $request->file('attachment'));

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan lembur berhasil dibuat.',
            'data'    => $created,
        ], 201);
    }

    /**
     * Show overtime request details.
     */
    public function show(int $id): JsonResponse
    {
        $overtime = OvertimeRequest::with([
            'employee:id,name,employee_id,division_id,position_id,supervisor_id',
            'attendance',
            'approvedBy:id,name,role',
            'rejectedBy:id,name,role',
        ])->findOrFail($id);

        $this->authorize('view', $overtime);

        return response()->json([
            'success' => true,
            'data'    => $overtime,
        ]);
    }

    /**
     * Approve an overtime request.
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        $actor = Auth::user();
        $overtime = OvertimeRequest::with('employee')->findOrFail($id);

        $this->authorize('approve', $overtime);

        $approvedMinutes = $request->has('approved_minutes') ? (int) $request->input('approved_minutes') : null;
        $approved = $this->service->approveRequest($actor, $overtime, $approvedMinutes);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan lembur berhasil disetujui.',
            'data'    => $approved,
        ]);
    }

    /**
     * Reject an overtime request.
     */
    public function reject(int $id, Request $request): JsonResponse
    {
        $actor = Auth::user();
        $overtime = OvertimeRequest::with('employee')->findOrFail($id);

        $this->authorize('reject', $overtime);

        $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        $rejected = $this->service->rejectRequest($actor, $overtime, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan lembur berhasil ditolak.',
            'data'    => $rejected,
        ]);
    }

    /**
     * Cancel an overtime request.
     */
    public function cancel(int $id): JsonResponse
    {
        $actor = Auth::user();
        $overtime = OvertimeRequest::with('employee')->findOrFail($id);

        $this->authorize('cancel', $overtime);

        $cancelled = $this->service->cancelRequest($actor, $overtime);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan lembur berhasil dibatalkan.',
            'data'    => $cancelled,
        ]);
    }

    /**
     * Download supporting document attachment.
     */
    public function attachment(int $id): BinaryFileResponse|JsonResponse
    {
        $actor = Auth::user();
        $overtime = OvertimeRequest::with('employee')->findOrFail($id);

        return $this->service->downloadAttachment($actor, $overtime);
    }

    /**
     * Get overtime metrics.
     */
    public function metrics(): JsonResponse
    {
        $actor = Auth::user();
        $role = strtolower((string) $actor->role);

        if (in_array($role, ['developer', 'devops', 'security_engineer', 'building_admin'], true)) {
            return response()->json([
                'success' => false,
                'message' => "Akses ditolak untuk peran {$role}.",
            ], 403);
        }

        $query = OvertimeRequest::query();

        if (in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $actor->employee_id);
        } elseif ($role === 'supervisor') {
            $supervisorEmpId = $actor->employee_id ?? $actor->id;
            $query->whereHas('employee', function ($q) use ($supervisorEmpId) {
                $q->where('supervisor_id', $supervisorEmpId);
            });
        }

        $countsByStatus = (clone $query)->selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')->toArray();
        $totalApprovedMinutes = (clone $query)->where('status', OvertimeRequest::STATUS_APPROVED)->sum('approved_minutes');

        return response()->json([
            'success' => true,
            'data'    => [
                'total'                  => (clone $query)->count(),
                'submitted'              => $countsByStatus[OvertimeRequest::STATUS_SUBMITTED] ?? 0,
                'approved'               => $countsByStatus[OvertimeRequest::STATUS_APPROVED] ?? 0,
                'rejected'               => $countsByStatus[OvertimeRequest::STATUS_REJECTED] ?? 0,
                'cancelled'              => $countsByStatus[OvertimeRequest::STATUS_CANCELLED] ?? 0,
                'total_approved_minutes' => (int) $totalApprovedMinutes,
            ],
        ]);
    }
}
