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

    /**
     * Dashboard KPI Metrics
     */
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

    /**
     * List Categories
     */
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

    /**
     * List Assets (Inventory)
     */
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

    /**
     * Create Asset
     */
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

    /**
     * View Asset Detail
     */
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

    /**
     * Update Asset
     */
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

    /**
     * List Assignments
     */
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

    /**
     * Assign Asset to Employee or Intern
     */
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

    /**
     * Return Asset
     */
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

    /**
     * List Maintenance Records
     */
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

    /**
     * Open Maintenance / Repair Ticket
     */
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

    /**
     * Complete Maintenance Ticket
     */
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

    /**
     * List Incidents (Lost/Damage/Theft)
     */
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

    /**
     * Report Incident
     */
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

    /**
     * Resolve Incident
     */
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

    /**
     * Dispose / Retire Asset
     */
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
