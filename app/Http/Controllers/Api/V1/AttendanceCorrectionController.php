<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrectionRequest;
use App\Services\AttendanceCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceCorrectionController extends Controller
{
    public function __construct(
        protected AttendanceCorrectionService $service
    ) {}

    /**
     * List attendance correction requests with scoping and filters.
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

        $query = AttendanceCorrectionRequest::with([
            'employee:id,name,employee_id,division_id,position_id,supervisor_id',
            'attendance:id,attendance_date,status,clock_in_at,clock_out_at',
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
        if ($request->filled('request_type')) {
            $query->where('request_type', strtoupper($request->query('request_type')));
        }
        if ($request->filled('employee_id') && !in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $request->query('employee_id'));
        }
        if ($request->filled('from_date')) {
            $query->whereDate('correction_date', '>=', $request->query('from_date'));
        }
        if ($request->filled('to_date')) {
            $query->whereDate('correction_date', '<=', $request->query('to_date'));
        }

        $corrections = $query->orderByDesc('created_at')->paginate($request->input('per_page', 15));

        return response()->json([
            'success' => true,
            'data'    => $corrections->items(),
            'meta'    => [
                'current_page' => $corrections->currentPage(),
                'last_page'    => $corrections->lastPage(),
                'per_page'     => $corrections->perPage(),
                'total'        => $corrections->total(),
            ],
        ]);
    }

    /**
     * Submit a new attendance correction request.
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
            'correction_date'           => 'required|date',
            'request_type'              => 'required|string|in:' . implode(',', AttendanceCorrectionRequest::TYPES),
            'requested_check_in'        => 'nullable|date',
            'requested_check_out'       => 'nullable|date',
            'requested_status'          => 'nullable|string|max:32',
            'requested_attendance_type' => 'nullable|string|max:32',
            'reason'                    => 'required|string|min:3|max:1000',
            'evidence_note'             => 'nullable|string|max:1000',
            'employee_id'               => 'nullable|integer|exists:employees,id',
            'attachment'                => 'nullable|file|mimes:pdf,jpg,jpeg,png,webp|max:5120',
        ]);

        $created = $this->service->createRequest($actor, $validated, $request->file('attachment'));

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan koreksi presensi berhasil dibuat.',
            'data'    => $created,
        ], 201);
    }

    /**
     * Show attendance correction request details.
     */
    public function show(int $id): JsonResponse
    {
        $correction = AttendanceCorrectionRequest::with([
            'employee:id,name,employee_id,division_id,position_id,supervisor_id',
            'attendance',
            'approvedBy:id,name,role',
            'rejectedBy:id,name,role',
        ])->findOrFail($id);

        $this->authorize('view', $correction);

        return response()->json([
            'success' => true,
            'data'    => $correction,
        ]);
    }

    /**
     * Approve an attendance correction request.
     */
    public function approve(int $id): JsonResponse
    {
        $actor = Auth::user();
        $correction = AttendanceCorrectionRequest::with('employee')->findOrFail($id);

        $this->authorize('approve', $correction);

        $approved = $this->service->approveRequest($actor, $correction);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan koreksi presensi berhasil disetujui.',
            'data'    => $approved,
        ]);
    }

    /**
     * Reject an attendance correction request.
     */
    public function reject(int $id, Request $request): JsonResponse
    {
        $actor = Auth::user();
        $correction = AttendanceCorrectionRequest::with('employee')->findOrFail($id);

        $this->authorize('reject', $correction);

        $request->validate([
            'reason' => 'required|string|min:3|max:1000',
        ]);

        $rejected = $this->service->rejectRequest($actor, $correction, $request->input('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan koreksi presensi berhasil ditolak.',
            'data'    => $rejected,
        ]);
    }

    /**
     * Cancel an attendance correction request.
     */
    public function cancel(int $id): JsonResponse
    {
        $actor = Auth::user();
        $correction = AttendanceCorrectionRequest::with('employee')->findOrFail($id);

        $this->authorize('cancel', $correction);

        $cancelled = $this->service->cancelRequest($actor, $correction);

        return response()->json([
            'success' => true,
            'message' => 'Pengajuan koreksi presensi berhasil dibatalkan.',
            'data'    => $cancelled,
        ]);
    }

    /**
     * Download supporting document attachment.
     */
    public function attachment(int $id): BinaryFileResponse|JsonResponse
    {
        $actor = Auth::user();
        $correction = AttendanceCorrectionRequest::with('employee')->findOrFail($id);

        return $this->service->downloadAttachment($actor, $correction);
    }

    /**
     * Get correction metrics.
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

        $query = AttendanceCorrectionRequest::query();

        if (in_array($role, ['employee', 'intern'], true)) {
            $query->where('employee_id', $actor->employee_id);
        } elseif ($role === 'supervisor') {
            $supervisorEmpId = $actor->employee_id ?? $actor->id;
            $query->whereHas('employee', function ($q) use ($supervisorEmpId) {
                $q->where('supervisor_id', $supervisorEmpId);
            });
        }

        $countsByStatus = (clone $query)->selectRaw('status, count(*) as cnt')->groupBy('status')->pluck('cnt', 'status')->toArray();

        return response()->json([
            'success' => true,
            'data'    => [
                'total'     => (clone $query)->count(),
                'submitted' => $countsByStatus[AttendanceCorrectionRequest::STATUS_SUBMITTED] ?? 0,
                'approved'  => $countsByStatus[AttendanceCorrectionRequest::STATUS_APPROVED] ?? 0,
                'rejected'  => $countsByStatus[AttendanceCorrectionRequest::STATUS_REJECTED] ?? 0,
                'cancelled' => $countsByStatus[AttendanceCorrectionRequest::STATUS_CANCELLED] ?? 0,
            ],
        ]);
    }
}
