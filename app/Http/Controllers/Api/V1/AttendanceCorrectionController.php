<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceCorrectionRequest;
use App\Services\AttendanceCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceCorrectionController extends Controller
{
    public function __construct(
        protected AttendanceCorrectionService $service
    ) {}

    #[OA\Get(
        path: '/api/v1/attendance-corrections',
        summary: 'Daftar Pengajuan Koreksi Presensi',
        description: 'Mengambil daftar permohonan koreksi data jam masuk/keluar atau status presensi.',
        security: [['sanctum' => []]],
        tags: ['Attendance Corrections'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter status (SUBMITTED, APPROVED, REJECTED, CANCELLED)', required: false, schema: new OA\Schema(type: 'string', example: 'SUBMITTED')),
            new OA\Parameter(name: 'request_type', in: 'query', description: 'Tipe koreksi', required: false, schema: new OA\Schema(type: 'string', example: 'MISSING_IN')),
            new OA\Parameter(name: 'employee_id', in: 'query', description: 'Filter ID Karyawan', required: false, schema: new OA\Schema(type: 'integer', example: 10)),
            new OA\Parameter(name: 'from_date', in: 'query', description: 'Tanggal awal koreksi', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-01')),
            new OA\Parameter(name: 'to_date', in: 'query', description: 'Tanggal akhir koreksi', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-31')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Jumlah item per halaman', required: false, schema: new OA\Schema(type: 'integer', example: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar permohonan koreksi berhasil didapatkan'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/attendance-corrections',
        summary: 'Ajukan Permohonan Koreksi Presensi',
        description: 'Membuat permohonan koreksi jam masuk/keluar atau status presensi karena lupa tap / kendala teknis.',
        security: [['sanctum' => []]],
        tags: ['Attendance Corrections'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['correction_date', 'request_type', 'reason'],
                    properties: [
                        new OA\Property(property: 'correction_date', type: 'string', format: 'date', example: '2026-03-15'),
                        new OA\Property(property: 'request_type', type: 'string', example: 'MISSING_IN'),
                        new OA\Property(property: 'requested_check_in', type: 'string', format: 'date-time', example: '2026-03-15T08:00:00Z'),
                        new OA\Property(property: 'requested_check_out', type: 'string', format: 'date-time', example: '2026-03-15T17:00:00Z'),
                        new OA\Property(property: 'requested_status', type: 'string', example: 'PRESENT'),
                        new OA\Property(property: 'reason', type: 'string', example: 'Lupa tap masuk karena ada antrean perbaikan gerbang turnstile'),
                        new OA\Property(property: 'evidence_note', type: 'string', example: 'Dikonfirmasi oleh supervisor area'),
                        new OA\Property(property: 'employee_id', type: 'integer', example: 10),
                        new OA\Property(property: 'attachment', type: 'string', format: 'binary', description: 'Dokumen / foto bukti pendukung (PDF/JPG/PNG)'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Pengajuan koreksi presensi berhasil dibuat'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 422, description: 'Validasi gagal')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/attendance-corrections/{id}',
        summary: 'Detail Pengajuan Koreksi Presensi',
        description: 'Mengambil detail permohonan koreksi presensi berdasarkan ID.',
        security: [['sanctum' => []]],
        tags: ['Attendance Corrections'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Koreksi', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail koreksi presensi berhasil ditemukan'),
            new OA\Response(response: 404, description: 'Pengajuan tidak ditemukan')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/attendance-corrections/{id}/approve',
        summary: 'Setujui Pengajuan Koreksi Presensi',
        description: 'Menyetujui permohonan koreksi presensi dan memperbarui data presensi terkait.',
        security: [['sanctum' => []]],
        tags: ['Attendance Corrections'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Koreksi', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pengajuan koreksi presensi berhasil disetujui'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/attendance-corrections/{id}/reject',
        summary: 'Tolak Pengajuan Koreksi Presensi',
        description: 'Menolak permohonan koreksi presensi beserta catatan alasan penolakan.',
        security: [['sanctum' => []]],
        tags: ['Attendance Corrections'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Koreksi', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Bukti pendukung tidak mencukupi untuk memverifikasi kedatangan'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pengajuan koreksi presensi berhasil ditolak'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 422, description: 'Alasan penolakan wajib diisi')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/attendance-corrections/{id}/cancel',
        summary: 'Batalkan Pengajuan Koreksi Presensi',
        description: 'Membatalkan permohonan koreksi presensi yang belum diproses.',
        security: [['sanctum' => []]],
        tags: ['Attendance Corrections'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Koreksi', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pengajuan koreksi presensi berhasil dibatalkan'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/attendance-corrections/{id}/attachment',
        summary: 'Unduh Berkas Lampiran Koreksi Presensi',
        description: 'Mengunduh atau stream berkas pendukung permohonan koreksi presensi.',
        security: [['sanctum' => []]],
        tags: ['Attendance Corrections'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Koreksi', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Berkas lampiran dikembalikan (Binary stream)'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 404, description: 'Berkas tidak ditemukan')
        ]
    )]
    public function attachment(int $id): BinaryFileResponse|JsonResponse
    {
        $actor = Auth::user();
        $correction = AttendanceCorrectionRequest::with('employee')->findOrFail($id);

        return $this->service->downloadAttachment($actor, $correction);
    }

    #[OA\Get(
        path: '/api/v1/attendance-corrections/metrics',
        summary: 'Metrik & Statistik Koreksi Presensi',
        description: 'Mengambil statistik jumlah pengajuan koreksi presensi berdasarkan status.',
        security: [['sanctum' => []]],
        tags: ['Attendance Corrections'],
        responses: [
            new OA\Response(response: 200, description: 'Metrik koreksi presensi berhasil didapatkan'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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
