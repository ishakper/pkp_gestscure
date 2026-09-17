<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRequest;
use App\Services\AttendanceRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AttendanceRequestController extends Controller
{
    public function __construct(protected AttendanceRequestService $service) {}

    #[OA\Get(
        path: '/api/v1/attendance-requests',
        summary: 'Daftar Pengajuan Izin / Cuti / WFH / Sakit',
        description: 'Mengambil daftar permohonan ketidakhadiran (WFH, Cuti, Izin, Sakit) dengan filter status dan peran.',
        security: [['sanctum' => []]],
        tags: ['Attendance Requests'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter status (SUBMITTED, APPROVED, REJECTED, CANCELLED)', required: false, schema: new OA\Schema(type: 'string', example: 'SUBMITTED')),
            new OA\Parameter(name: 'request_type', in: 'query', description: 'Tipe pengajuan (WFH, LEAVE, PERMISSION, SICK)', required: false, schema: new OA\Schema(type: 'string', example: 'WFH')),
            new OA\Parameter(name: 'employee_id', in: 'query', description: 'Filter ID Karyawan', required: false, schema: new OA\Schema(type: 'integer', example: 10)),
            new OA\Parameter(name: 'from_date', in: 'query', description: 'Tanggal awal', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-01')),
            new OA\Parameter(name: 'to_date', in: 'query', description: 'Tanggal akhir', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-31')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Jumlah item per halaman', required: false, schema: new OA\Schema(type: 'integer', example: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar permohonan berhasil didapatkan'),
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

    #[OA\Post(
        path: '/api/v1/attendance-requests',
        summary: 'Ajukan Permohonan Absensi / Cuti / WFH',
        description: 'Membuat pengajuan permohonan izin, cuti, WFH, atau sakit beserta lampiran pendukung.',
        security: [['sanctum' => []]],
        tags: ['Attendance Requests'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['request_type', 'start_date', 'reason'],
                    properties: [
                        new OA\Property(property: 'request_type', type: 'string', enum: ['WFH', 'LEAVE', 'PERMISSION', 'SICK'], example: 'WFH'),
                        new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2026-03-20'),
                        new OA\Property(property: 'end_date', type: 'string', format: 'date', example: '2026-03-21'),
                        new OA\Property(property: 'reason', type: 'string', example: 'Kerja WFH karena ada perbaikan jaringan di rumah'),
                        new OA\Property(property: 'category', type: 'string', example: 'Annual Leave'),
                        new OA\Property(property: 'start_time', type: 'string', example: '08:00'),
                        new OA\Property(property: 'end_time', type: 'string', example: '17:00'),
                        new OA\Property(property: 'employee_id', type: 'integer', example: 10),
                        new OA\Property(property: 'attachment', type: 'string', format: 'binary', description: 'Surat dokter / berkas pendukung (PDF/JPG/PNG)'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Permohonan absensi berhasil diajukan'),
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

    #[OA\Get(
        path: '/api/v1/attendance-requests/{id}',
        summary: 'Detail Pengajuan Absensi',
        description: 'Mengambil detail permohonan absensi berdasarkan ID.',
        security: [['sanctum' => []]],
        tags: ['Attendance Requests'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pengajuan', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail permohonan berhasil ditemukan'),
            new OA\Response(response: 404, description: 'Permohonan tidak ditemukan')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/attendance-requests/{id}/approve',
        summary: 'Setujui Pengajuan Absensi',
        description: 'Menyetujui permohonan absensi oleh atasan / HRD.',
        security: [['sanctum' => []]],
        tags: ['Attendance Requests'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pengajuan', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'notes', type: 'string', example: 'Disetujui untuk WFH'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Permohonan absensi berhasil disetujui'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/attendance-requests/{id}/reject',
        summary: 'Tolak Pengajuan Absensi',
        description: 'Menolak permohonan absensi beserta catatan alasan penolakan.',
        security: [['sanctum' => []]],
        tags: ['Attendance Requests'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pengajuan', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Jadwal shift sangat padat pada tanggal tersebut'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Permohonan absensi berhasil ditolak'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 422, description: 'Alasan penolakan wajib diisi')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/attendance-requests/{id}/cancel',
        summary: 'Batalkan Pengajuan Absensi',
        description: 'Membatalkan permohonan absensi yang belum diproses.',
        security: [['sanctum' => []]],
        tags: ['Attendance Requests'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pengajuan', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Batal karena jadwal kerja berubah'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Permohonan absensi berhasil dibatalkan'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/attendance-requests/{id}/attachment',
        summary: 'Unduh Berkas Lampiran Pengajuan Absensi',
        description: 'Mengunduh atau stream berkas surat/dokumen pendukung pengajuan absensi secara aman.',
        security: [['sanctum' => []]],
        tags: ['Attendance Requests'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Pengajuan', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Berkas lampiran dikembalikan (Binary stream)'),
            new OA\Response(response: 403, description: 'Akses ditolak / tidak memiliki kewenangan'),
            new OA\Response(response: 404, description: 'Berkas lampiran tidak ditemukan')
        ]
    )]
    public function attachment(int $id): BinaryFileResponse|JsonResponse
    {
        $actor = Auth::user();
        $req = AttendanceRequest::with('employee')->findOrFail($id);

        $this->authorize('downloadAttachment', $req);

        return $this->service->downloadAttachment($actor, $req);
    }

    #[OA\Get(
        path: '/api/v1/attendance-requests/metrics',
        summary: 'Metrik & Statistik Pengajuan Absensi',
        description: 'Mengambil statistik jumlah permohonan absensi berdasarkan status dan tipe.',
        security: [['sanctum' => []]],
        tags: ['Attendance Requests'],
        responses: [
            new OA\Response(response: 200, description: 'Metrik pengajuan absensi berhasil didapatkan'),
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
