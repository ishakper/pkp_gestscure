<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OvertimeRequest;
use App\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OvertimeController extends Controller
{
    public function __construct(
        protected OvertimeService $service
    ) {}

    #[OA\Get(
        path: '/api/v1/overtime-requests',
        summary: 'Daftar Pengajuan Lembur',
        description: 'Mengambil daftar permohonan lembur karyawan dengan filter status dan tanggal.',
        security: [['sanctum' => []]],
        tags: ['Overtime'],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', description: 'Filter status (SUBMITTED, APPROVED, REJECTED, CANCELLED)', required: false, schema: new OA\Schema(type: 'string', example: 'SUBMITTED')),
            new OA\Parameter(name: 'employee_id', in: 'query', description: 'Filter ID Karyawan', required: false, schema: new OA\Schema(type: 'integer', example: 10)),
            new OA\Parameter(name: 'from_date', in: 'query', description: 'Tanggal awal lembur', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-01')),
            new OA\Parameter(name: 'to_date', in: 'query', description: 'Tanggal akhir lembur', required: false, schema: new OA\Schema(type: 'string', format: 'date', example: '2026-03-31')),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Jumlah item per halaman', required: false, schema: new OA\Schema(type: 'integer', example: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Daftar permohonan lembur berhasil didapatkan'),
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

    #[OA\Post(
        path: '/api/v1/overtime-requests',
        summary: 'Ajukan Permohonan Lembur',
        description: 'Membuat pengajuan lembur kerja beserta durasi, referensi tugas proyek, dan lampiran.',
        security: [['sanctum' => []]],
        tags: ['Overtime'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['overtime_date', 'requested_start', 'requested_end', 'reason'],
                    properties: [
                        new OA\Property(property: 'overtime_date', type: 'string', format: 'date', example: '2026-03-25'),
                        new OA\Property(property: 'requested_start', type: 'string', example: '17:30'),
                        new OA\Property(property: 'requested_end', type: 'string', example: '20:30'),
                        new OA\Property(property: 'reason', type: 'string', example: 'Penyelesaian migrasi server basis data produksi'),
                        new OA\Property(property: 'project_task_reference', type: 'string', example: 'TASK-9082'),
                        new OA\Property(property: 'employee_id', type: 'integer', example: 10),
                        new OA\Property(property: 'attachment', type: 'string', format: 'binary', description: 'Surat perintah lembur / berkas pendukung (PDF/JPG/PNG)'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Pengajuan lembur berhasil dibuat'),
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

    #[OA\Get(
        path: '/api/v1/overtime-requests/{id}',
        summary: 'Detail Pengajuan Lembur',
        description: 'Mengambil detail permohonan lembur berdasarkan ID.',
        security: [['sanctum' => []]],
        tags: ['Overtime'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lembur', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail pengajuan lembur berhasil ditemukan'),
            new OA\Response(response: 404, description: 'Pengajuan tidak ditemukan')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/overtime-requests/{id}/approve',
        summary: 'Setujui Pengajuan Lembur',
        description: 'Menyetujui permohonan lembur dan menetapkan total durasi menit lembur disetujui.',
        security: [['sanctum' => []]],
        tags: ['Overtime'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lembur', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'approved_minutes', type: 'integer', example: 180, description: 'Jumlah menit lembur yang disetujui'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pengajuan lembur berhasil disetujui'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/overtime-requests/{id}/reject',
        summary: 'Tolak Pengajuan Lembur',
        description: 'Menolak permohonan lembur beserta catatan alasan penolakan.',
        security: [['sanctum' => []]],
        tags: ['Overtime'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lembur', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['reason'],
                properties: [
                    new OA\Property(property: 'reason', type: 'string', example: 'Anggaran lembur departemen bulan ini sudah mencapai limit'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pengajuan lembur berhasil ditolak'),
            new OA\Response(response: 403, description: 'Akses ditolak'),
            new OA\Response(response: 422, description: 'Alasan penolakan wajib diisi')
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/overtime-requests/{id}/cancel',
        summary: 'Batalkan Pengajuan Lembur',
        description: 'Membatalkan permohonan lembur yang belum diproses.',
        security: [['sanctum' => []]],
        tags: ['Overtime'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lembur', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Pengajuan lembur berhasil dibatalkan'),
            new OA\Response(response: 403, description: 'Akses ditolak')
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/overtime-requests/{id}/attachment',
        summary: 'Unduh Berkas Lampiran Pengajuan Lembur',
        description: 'Mengunduh atau stream berkas pendukung permohonan lembur.',
        security: [['sanctum' => []]],
        tags: ['Overtime'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Lembur', required: true, schema: new OA\Schema(type: 'integer', example: 1)),
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
        $overtime = OvertimeRequest::with('employee')->findOrFail($id);

        return $this->service->downloadAttachment($actor, $overtime);
    }

    #[OA\Get(
        path: '/api/v1/overtime-requests/metrics',
        summary: 'Metrik & Statistik Pengajuan Lembur',
        description: 'Mengambil statistik jumlah pengajuan lembur dan akumulasi total menit disetujui.',
        security: [['sanctum' => []]],
        tags: ['Overtime'],
        responses: [
            new OA\Response(response: 200, description: 'Metrik pengajuan lembur berhasil didapatkan'),
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
