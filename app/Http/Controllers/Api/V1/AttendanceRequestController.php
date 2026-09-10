<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRequest;
use App\Services\AttendanceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceRequestController extends Controller
{
    public function __construct(protected AttendanceRequestService $service) {}

    /**
     * List attendance requests with filtering and role scoping.
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

        $query = AttendanceRequest::with([
            'employee:id,name,employee_id,division_id,position_id,supervisor_id',
            'approver:id,name,role',
            'rejecter:id,name,role',
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
        if ($request->filled('request_type')) {
            $query->where('request_type', strtoupper($request->query('request_type')));
        }
        if ($request->filled('employee_id') && !in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('from_date')) {
            $query->where('end_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->where('start_date', '<=', $request->query('to_date'));
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
     * Submit a new attendance request.
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
            'request_type' => 'required|string|in:WFH,LEAVE,PERMISSION,SICK',
            'start_date'   => 'required|date',
            'end_date'     => 'nullable|date|after_or_equal:start_date',
            'reason'       => 'required|string|min:3|max:1000',
            'category'     => 'nullable|string|max:50',
            'start_time'   => 'nullable|string|max:10',
            'end_time'     => 'nullable|string|max:10',
            'employee_id'  => 'nullable|integer|exists:employees,id',
            'metadata'     => 'nullable|array',
            'attachment'   => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        $created = $this->service->createRequest($actor, $validated, $request->file('attachment'));

        return response()->json([
            'success' => true,
            'message' => 'Permohonan absensi berhasil diajukan.',
            'data'    => $created,
        ], 201);
    }

    /**
     * Show attendance request details.
     */
    public function show(int $id): JsonResponse
    {
        $actor = Auth::user();
        $request = AttendanceRequest::with([
            'employee:id,name,employee_id,division_id,position_id,supervisor_id',
            'approver:id,name,role',
            'rejecter:id,name,role',
        ])->findOrFail($id);

        $this->authorize('view', $request);

        return response()->json([
            'success' => true,
            'data'    => $request,
        ]);
    }

    /**
     * Approve an attendance request.
     */
    public function approve(int $id, Request $request): JsonResponse
    {
        $actor = Auth::user();
        $req = AttendanceRequest::with('employee')->findOrFail($id);

        $this->authorize('approve', $req);

        $approved = $this->service->approveRequest($actor, $req, $request->input('notes'));

        return response()->json([
            'success' => true,
            'message' => 'Permohonan absensi berhasil disetujui.',
            'data'    => $approved,
        ]);
    }

    /**
     * Reject an attendance request.
     */
    public function reject(int $id, Request $request): JsonResponse
    {
        $actor = Auth::user();
        $req = AttendanceRequest::with('employee')->findOrFail($id);

        $this->authorize('reject', $req);

        $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        $rejected = $this->service->rejectRequest($actor, $req, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Permohonan absensi berhasil ditolak.',
            'data'    => $rejected,
        ]);
    }

    /**
     * Cancel an attendance request.
     */
    public function cancel(int $id, Request $request): JsonResponse
    {
        $actor = Auth::user();
        $req = AttendanceRequest::with('employee')->findOrFail($id);

        $this->authorize('cancel', $req);

        $cancelled = $this->service->cancelRequest($actor, $req, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Permohonan absensi berhasil dibatalkan.',
            'data'    => $cancelled,
        ]);
    }

    /**
     * Securely download supporting attachment.
     */
    public function attachment(int $id): BinaryFileResponse|JsonResponse
    {
        $actor = Auth::user();
        $req = AttendanceRequest::with('employee')->findOrFail($id);

        $this->authorize('downloadAttachment', $req);

        return $this->service->downloadAttachment($actor, $req);
    }

    /**
     * Get request metrics.
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

        $query = AttendanceRequest::query();

        if (in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $actor->employee_id);
        } elseif ($role === 'supervisor') {
            $supervisorEmpId = $actor->employee_id ?? $actor->id;
            $query->whereHas('employee', function ($q) use ($supervisorEmpId) {
                $q->where('supervisor_id', $supervisorEmpId);
            });
        }

        $countsByStatus = (clone $query)->selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')->toArray();
        $countsByType   = (clone $query)->selectRaw('request_type, count(*) as cnt')->groupBy('request_type')->pluck('cnt', 'request_type')->toArray();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'            => (clone $query)->count(),
                'submitted'        => $countsByStatus[AttendanceRequest::STATUS_SUBMITTED] ?? 0,
                'approved'         => $countsByStatus[AttendanceRequest::STATUS_APPROVED] ?? 0,
                'rejected'         => $countsByStatus[AttendanceRequest::STATUS_REJECTED] ?? 0,
                'cancelled'        => $countsByStatus[AttendanceRequest::STATUS_CANCELLED] ?? 0,
                'wfh_count'        => $countsByType[AttendanceRequest::TYPE_WFH] ?? 0,
                'leave_count'      => $countsByType[AttendanceRequest::TYPE_LEAVE] ?? 0,
                'permission_count' => $countsByType[AttendanceRequest::TYPE_PERMISSION] ?? 0,
                'sick_count'       => $countsByType[AttendanceRequest::TYPE_SICK] ?? 0,
            ],
        ]);
    }
}
