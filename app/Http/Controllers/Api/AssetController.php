<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetCategory;
use App\Models\AssetIncident;
use App\Models\AssetMaintenance;
use App\Policies\AssetPolicy;
use App\Services\AssetService;
use App\Services\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class AssetController extends Controller
{
    protected AssetService $service;
    protected AssetPolicy $policy;
    protected PortalAccess $portalAccess;

    public function __construct(
        AssetService $service,
        AssetPolicy $policy,
        PortalAccess $portalAccess
    ) {
        $this->service = $service;
        $this->policy = $policy;
        $this->portalAccess = $portalAccess;
    }

    #[OA\Get(
        path: '/assets/metrics',
        summary: 'Metrik Inventaris Aset',
        description: 'Mendapatkan statistik total aset, nilai perolehan, alokasi karyawan, dan pemeliharaan.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Metrik aset berhasil diambil')
        ]
    )]
    public function metrics(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view asset metrics.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->service->getMetrics($actor),
        ]);
    }

    #[OA\Get(
        path: '/assets/categories',
        summary: 'Kategori Aset Perusahaan',
        description: 'Mendapatkan daftar kategori inventaris aset.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Kategori aset berhasil diambil')
        ]
    )]
    public function categories(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view categories.'], 403);
        }

        $this->service->ensureStandardCategories();
        return response()->json([
            'success' => true,
            'data' => AssetCategory::orderBy('name')->get(),
        ]);
    }

    #[OA\Get(
        path: '/assets',
        summary: 'Daftar Inventaris Aset',
        description: 'Mendapatkan daftar aset perusahaan dengan filter kondisi dan lokasi.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar aset berhasil diambil')
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view assets.'], 403);
        }

        $filters = $request->only(['status', 'category_id', 'building_name', 'condition', 'search']);
        $assets = $this->service->getAssets($filters, $actor);

        return response()->json([
            'success' => true,
            'data' => $assets,
        ]);
    }

    #[OA\Post(
        path: '/assets',
        summary: 'Registrasi Aset Baru',
        description: 'Mendaftarkan item aset inventaris baru.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['asset_name'],
                properties: [
                    new OA\Property(property: 'asset_name', type: 'string', example: 'Laptop ThinkPad X1 Carbon'),
                    new OA\Property(property: 'brand', type: 'string', example: 'Lenovo'),
                    new OA\Property(property: 'model', type: 'string', example: 'Gen 10'),
                    new OA\Property(property: 'serial_number', type: 'string', example: 'SN-99887766')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Aset berhasil didaftarkan')
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->create($actor)) {
            return response()->json(['message' => 'Unauthorized to register new assets.'], 403);
        }

        $validated = $request->validate([
            'asset_name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:asset_categories,id',
            'brand' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100|unique:assets,serial_number',
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'vendor' => 'nullable|string|max:255',
            'warranty_start' => 'nullable|date',
            'warranty_end' => 'nullable|date',
            'building_name' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'division_id' => 'nullable|integer',
            'condition' => 'nullable|string|in:NEW,GOOD,FAIR,POOR,DAMAGED',
            'notes' => 'nullable|string',
        ]);

        $asset = $this->service->createAsset($validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil didaftarkan.',
            'data' => $asset,
        ], 201);
    }

    #[OA\Get(
        path: '/assets/{id}',
        summary: 'Detail Item Aset',
        description: 'Mendapatkan rincian aset inventaris.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Aset', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detail aset ditemukan')
        ]
    )]
    public function show(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $asset = $this->service->getAssetById($id, $actor);

        if (!$this->policy->view($actor, $asset)) {
            return response()->json(['message' => 'Unauthorized to view this asset.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $asset,
        ]);
    }

    #[OA\Put(
        path: '/assets/{id}',
        summary: 'Perbarui Data Aset',
        description: 'Memperbarui data aset.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Aset', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Data aset berhasil diperbarui')
        ]
    )]
    public function update(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $asset = $this->service->getAssetById($id, $actor);

        if (!$this->policy->update($actor, $asset)) {
            return response()->json(['message' => 'Unauthorized to update this asset.'], 403);
        }

        $validated = $request->validate([
            'asset_name' => 'sometimes|required|string|max:255',
            'category_id' => 'nullable|exists:asset_categories,id',
            'brand' => 'nullable|string|max:100',
            'model' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:100|unique:assets,serial_number,' . $id,
            'purchase_date' => 'nullable|date',
            'purchase_price' => 'nullable|numeric|min:0',
            'vendor' => 'nullable|string|max:255',
            'warranty_start' => 'nullable|date',
            'warranty_end' => 'nullable|date',
            'building_name' => 'nullable|string|max:100',
            'location' => 'nullable|string|max:255',
            'division_id' => 'nullable|integer',
            'condition' => 'nullable|string|in:NEW,GOOD,FAIR,POOR,DAMAGED',
            'notes' => 'nullable|string',
        ]);

        $updated = $this->service->updateAsset($asset, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Data aset berhasil diperbarui.',
            'data' => $updated,
        ]);
    }

    #[OA\Get(
        path: '/assets/assignments',
        summary: 'Daftar Alokasi Aset Karyawan',
        description: 'Mendapatkan daftar alokasi peminjaman aset.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar alokasi aset berhasil diambil')
        ]
    )]
    public function assignments(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view assignments.'], 403);
        }

        $filters = $request->only(['status', 'employee_id', 'asset_id']);
        $assignments = $this->service->getAssignments($filters, $actor);

        return response()->json([
            'success' => true,
            'data' => $assignments,
        ]);
    }

    #[OA\Post(
        path: '/assets/{id}/assign',
        summary: 'Alokasikan Aset ke Karyawan / Magang',
        description: 'Menyerahkan fasilitas aset perusahaan ke karyawan.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Aset', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'employee_id', type: 'integer', example: 1),
                    new OA\Property(property: 'handover_notes', type: 'string', example: 'Serah terima laptop kerja')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Aset berhasil dialokasikan')
        ]
    )]
    public function assign(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $asset = $this->service->getAssetById($id, $actor);

        if (!$this->policy->assign($actor, $asset)) {
            return response()->json(['message' => 'Unauthorized to assign this asset.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'nullable|exists:employees,id',
            'internship_id' => 'nullable|exists:internships,id',
            'assigned_at' => 'nullable|date',
            'expected_return_date' => 'nullable|date',
            'condition_out' => 'nullable|string|in:NEW,GOOD,FAIR,POOR,DAMAGED',
            'accessories' => 'nullable|array',
            'handover_notes' => 'nullable|string',
        ]);

        $assignment = $this->service->assignAsset($asset, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil dialokasikan kepada penerima.',
            'data' => $assignment,
        ]);
    }

    #[OA\Post(
        path: '/assets/assignments/{id}/return',
        summary: 'Proses Pengembalian Aset',
        description: 'Memproses pengembalian aset dari karyawan.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Penugasan Aset', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['condition_in'],
                properties: [
                    new OA\Property(property: 'condition_in', type: 'string', example: 'GOOD'),
                    new OA\Property(property: 'return_notes', type: 'string', example: 'Kembali lengkap beserta adapter charger')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pengembalian aset berhasil diproses')
        ]
    )]
    public function returnAsset(Request $request, int $assignmentId): JsonResponse
    {
        $actor = $request->user();
        $assignment = AssetAssignment::with('asset')->findOrFail($assignmentId);

        if (!$this->policy->returnAsset($actor, $assignment)) {
            return response()->json(['message' => 'Unauthorized to process asset return.'], 403);
        }

        $validated = $request->validate([
            'condition_in' => 'required|string|in:NEW,GOOD,FAIR,POOR,DAMAGED',
            'return_notes' => 'nullable|string',
        ]);

        $returned = $this->service->returnAsset($assignment, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Pengembalian aset berhasil diproses.',
            'data' => $returned,
        ]);
    }

    #[OA\Get(
        path: '/assets/maintenances',
        summary: 'Daftar Catatan Pemeliharaan Aset',
        description: 'Mendapatkan daftar tiket pemeliharaan aset.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar maintenance berhasil diambil')
        ]
    )]
    public function maintenances(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view maintenance records.'], 403);
        }

        $filters = $request->only(['status', 'asset_id']);
        $maintenances = $this->service->getMaintenances($filters, $actor);

        return response()->json([
            'success' => true,
            'data' => $maintenances,
        ]);
    }

    #[OA\Post(
        path: '/assets/{id}/maintenance',
        summary: 'Buka Tiket Perbaikan/Pemeliharaan',
        description: 'Membuka tiket perbaikan/pemeliharaan aset.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Aset', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['maintenance_type', 'issue_description'],
                properties: [
                    new OA\Property(property: 'maintenance_type', type: 'string', example: 'REPAIR'),
                    new OA\Property(property: 'issue_description', type: 'string', example: 'Keyboard beberapa tombol tidak merespon')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Tiket pemeliharaan berhasil dibuka')
        ]
    )]
    public function openMaintenance(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $asset = $this->service->getAssetById($id, $actor);

        if (!$this->policy->maintain($actor, $asset)) {
            return response()->json(['message' => 'Unauthorized to open maintenance for this asset.'], 403);
        }

        $validated = $request->validate([
            'maintenance_type' => 'required|string|in:PREVENTIVE,REPAIR,INSPECTION,WARRANTY',
            'issue_description' => 'required|string',
            'vendor' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $maintenance = $this->service->openMaintenance($asset, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Tiket pemeliharaan/perbaikan aset berhasil dibuka.',
            'data' => $maintenance,
        ]);
    }

    #[OA\Post(
        path: '/assets/maintenances/{id}/complete',
        summary: 'Selesaikan Tiket Perbaikan',
        description: 'Menutup tiket pemeliharaan setelah aset selesai diperbaiki.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Tiket Maintenance', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['result'],
                properties: [
                    new OA\Property(property: 'result', type: 'string', example: 'Pengantian modul keyboard selesai')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Pemeliharaan aset telah selesai')
        ]
    )]
    public function completeMaintenance(Request $request, int $maintenanceId): JsonResponse
    {
        $actor = $request->user();
        $maintenance = AssetMaintenance::with('asset')->findOrFail($maintenanceId);

        if (!$this->policy->maintain($actor, $maintenance->asset)) {
            return response()->json(['message' => 'Unauthorized to complete maintenance for this asset.'], 403);
        }

        $validated = $request->validate([
            'result' => 'required|string',
            'condition' => 'nullable|string|in:NEW,GOOD,FAIR,POOR,DAMAGED',
            'cost' => 'nullable|numeric|min:0',
        ]);

        $completed = $this->service->completeMaintenance($maintenance, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Pemeliharaan aset telah selesai dan aset kembali tersedia.',
            'data' => $completed,
        ]);
    }

    #[OA\Get(
        path: '/assets/incidents',
        summary: 'Daftar Laporan Insiden Aset',
        description: 'Mendapatkan daftar laporan insiden aset (rusak/hilang).',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Daftar insiden aset berhasil diambil')
        ]
    )]
    public function incidents(Request $request): JsonResponse
    {
        $actor = $request->user();
        if (!$this->policy->viewAny($actor)) {
            return response()->json(['message' => 'Unauthorized to view asset incidents.'], 403);
        }

        $filters = $request->only(['status', 'incident_type']);
        $incidents = $this->service->getIncidents($filters, $actor);

        return response()->json([
            'success' => true,
            'data' => $incidents,
        ]);
    }

    #[OA\Post(
        path: '/assets/{id}/incident',
        summary: 'Laporkan Insiden Aset (Hilang/Rusak)',
        description: 'Melaporkan insiden kerusakan atau kehilangan aset.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Aset', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['incident_type', 'description'],
                properties: [
                    new OA\Property(property: 'incident_type', type: 'string', example: 'DAMAGED'),
                    new OA\Property(property: 'description', type: 'string', example: 'Layar retak terkena benturan')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Laporan insiden berhasil diajukan')
        ]
    )]
    public function reportIncident(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $asset = Asset::findOrFail($id);

        if (!$this->policy->reportIncident($actor, $asset)) {
            return response()->json(['message' => 'Unauthorized to report incident for this asset.'], 403);
        }

        $validated = $request->validate([
            'incident_type' => 'required|string|in:LOST,DAMAGED,STOLEN,MISSING_ACCESSORY,OTHER',
            'description' => 'required|string',
            'employee_id' => 'nullable|exists:employees,id',
            'location' => 'nullable|string',
            'incident_date' => 'nullable|date',
        ]);

        $incident = $this->service->reportIncident($asset, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Laporan insiden aset berhasil diajukan.',
            'data' => $incident,
        ]);
    }

    #[OA\Post(
        path: '/assets/incidents/{id}/resolve',
        summary: 'Penyelesaian Insiden Aset',
        description: 'Menutup laporan insiden aset dengan resolusi penyelesaian.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Insiden', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['resolution'],
                properties: [
                    new OA\Property(property: 'resolution', type: 'string', example: 'Aset telah diganti klaim asuransi')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Insiden aset berhasil diselesaikan')
        ]
    )]
    public function resolveIncident(Request $request, int $incidentId): JsonResponse
    {
        $actor = $request->user();
        $incident = AssetIncident::findOrFail($incidentId);

        if (!$this->policy->resolveIncident($actor, $incident)) {
            return response()->json(['message' => 'Unauthorized to resolve asset incidents.'], 403);
        }

        $validated = $request->validate([
            'resolution' => 'required|string',
        ]);

        $resolved = $this->service->resolveIncident($incident, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Insiden aset berhasil diselesaikan.',
            'data' => $resolved,
        ]);
    }

    #[OA\Post(
        path: '/assets/{id}/dispose',
        summary: 'Penghapusbukuan / Disposal Aset',
        description: 'Menghapusbukukan aset yang tidak lagi layak pakai.',
        tags: ['Asset Management'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID Aset', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['disposal_reason'],
                properties: [
                    new OA\Property(property: 'disposal_reason', type: 'string', example: 'Rusak berat / usia pakai melebihi 5 tahun')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Aset berhasil dihapusbukukan')
        ]
    )]
    public function dispose(Request $request, int $id): JsonResponse
    {
        $actor = $request->user();
        $asset = $this->service->getAssetById($id, $actor);

        if (!$this->policy->dispose($actor, $asset)) {
            return response()->json(['message' => 'Unauthorized to dispose/retire assets.'], 403);
        }

        $validated = $request->validate([
            'disposal_reason' => 'required|string',
            'disposal_method' => 'nullable|string',
        ]);

        $disposed = $this->service->disposeAsset($asset, $validated, $actor);

        return response()->json([
            'success' => true,
            'message' => 'Aset berhasil dihapusbukukan (disposed).',
            'data' => $disposed,
        ]);
    }
}
