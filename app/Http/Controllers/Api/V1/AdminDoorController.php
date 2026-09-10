<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoorResource;
use App\Models\AccessLog;
use App\Models\ActivityLog;
use App\Models\Door;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use App\Services\PortalAccess;
use Illuminate\Http\Request;

class AdminDoorController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess) {}
    public function metrics(Request $request)
    {
        $admin = $request->user();
        $doors = Door::query();

        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $doors->where('location', $admin->assigned_building);
        }

        $scopedDoors = $doors->get();
        $doorIds = $scopedDoors->pluck('id');

        return response()->json([
            'status' => 'success',
            'data' => [
                'totalUsers' => Employee::count(),
                'activeDoors' => $scopedDoors->where('connection_status', 'online')->count(),
                'totalDoors' => $scopedDoors->count(),
                'grantedLogs' => AccessLog::whereIn('door_id', $doorIds)->where('access_status', 'Granted')->count(),
                'deniedLogs' => AccessLog::whereIn('door_id', $doorIds)->where('access_status', 'Denied')->count(),
            ],
        ]);
    }

    public function index(Request $request)
    {
        $admin = $request->user();
        $query = Door::with('building:id,name')->withCount(['employees', 'doorAssignments']);

        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $query->where('location', $admin->assigned_building);
        }

        $doors = $query->get();

        return response()->json([
            'status' => 'success',
            'total_doors' => $doors->count(),
            'data' => DoorResource::collection($doors),
        ]);
    }

    public function lookup(Request $request)
    {
        $admin = $request->user();
        $query = Door::query();

        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $query->where('location', $admin->assigned_building);
        }

        $doors = $query->get()->map(function ($door) {
            return [
                'id' => $door->id,
                'door_id' => $door->door_id,
                'name' => $door->name ?? $door->door_name,
                'location' => $door->location,
            ];
        });

        return response()->json([
            'status' => 'success',
            'data' => $doors,
        ]);
    }

    public function overrideStatus(Request $request, $door_id)
    {
        $door = Door::where('door_id', $door_id)->firstOrFail();

        $this->authorize('overrideStatus', $door);

        $request->validate([
            'is_manual_override' => 'required|boolean',
        ]);

        $door->update([
            'is_manual_override' => (bool) $request->is_manual_override,
        ]);

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'override_door_status',
            'subject_type' => 'Door',
            'subject_id' => $door->id,
            'description' => "Maintenance override for {$door->door_id} ({$door->door_name}) " . ($request->boolean('is_manual_override') ? 'enabled' : 'disabled'),
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Mode maintenance {$door->door_id} berhasil diperbarui tanpa mengubah status koneksi aktual",
            'data' => new DoorResource($door),
        ]);
    }

    /**
     * Remote unlock door via physical Hikvision ISAPI command
     */
    public function openDoor(Request $request, $door_id, HikvisionIsapiService $isapiService)
    {
        $door = Door::where('door_id', $door_id)->orWhere('id', $door_id)->firstOrFail();

        if ($request->user() && method_exists($this, 'authorize')) {
            $this->authorize('open', $door);
        }

        if ($door->connection_status !== 'online' || $door->health_status === 'auth_error') {
            return response()->json([
                'status' => 'error',
                'code' => 409,
                'message' => 'Remote unlock diblokir: terminal belum terverifikasi online.',
            ], 409);
        }

        $result = $isapiService->remoteControlDoor($door, 'open');

        if (!($result['status'] ?? false)) {
            return response()->json([
                'status' => 'error',
                'code' => $result['statusCode'] ?? 500,
                'message' => "Gagal membuka pintu: " . ($result['error'] ?? 'Device unreachable / hardware execution failed'),
            ], 500);
        }

        $doorName = $door->door_name ?? $door->name;

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'remote_door_opened',
            'subject_type' => 'Door',
            'subject_id' => $door->id,
            'description' => "Remote unlock triggered for {$door->door_id} ({$doorName}) via web dashboard.",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Pintu {$doorName} ({$door->door_id}) berhasil dibuka via remote.",
            'data' => new DoorResource($door->fresh()),
        ], 200);
    }

    /**
     * Audit single physical terminal connectivity via ISAPI getDeviceStatus
     */
    public function checkConnection(Request $request, $door_id, HikvisionIsapiService $isapiService)
    {
        $door = Door::where('door_id', $door_id)->orWhere('id', $door_id)->firstOrFail();

        $this->authorize('physicalControl', $door);

        $statusResult = $isapiService->getDeviceStatus($door);
        $isOnline = (bool) ($statusResult['status'] ?? false);
        $connStatus = $isOnline ? 'online' : 'offline';
        $healthStatus = $isOnline ? 'online' : ((int) ($statusResult['statusCode'] ?? 0) === 401 ? 'auth_error' : 'offline');

        $door->update([
            'status' => $connStatus,
            'connection_status' => $connStatus,
            'health_status' => $healthStatus,
            'is_manual_override' => false,
            'last_checked_at' => now(),
        ]);

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'isapi_connection_checked',
            'subject_type' => 'Door',
            'subject_id' => $door->id,
            'description' => "Audited ISAPI connection for {$door->door_id}: status={$connStatus}",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => $isOnline ? 'success' : 'error',
            'message' => $isOnline 
                ? "Terminal pintu {$door->door_id} ({$door->device_ip}) terhubung secara aktif." 
                : "Terminal pintu {$door->door_id} offline: " . ($statusResult['error'] ?? 'Device unreachable'),
            'is_online' => $isOnline,
            'health_status' => $healthStatus,
            'data' => new DoorResource($door->fresh()),
            'isapi_details' => $statusResult['data'] ?? null,
        ]);
    }

    /**
     * Audit all physical terminals connectivity via ISAPI
     */
    public function checkAllConnections(Request $request, HikvisionIsapiService $isapiService)
    {
        abort_unless($this->portalAccess->can($request->user(), 'device.manage'), 403);

        $admin = $request->user();
        $query = Door::query();

        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $query->where('location', $admin->assigned_building);
        }

        $doors = $query->get();
        $results = [];

        foreach ($doors as $door) {
            $statusResult = $isapiService->getDeviceStatus($door);
            $isOnline = (bool) ($statusResult['status'] ?? false);
            $connStatus = $isOnline ? 'online' : 'offline';
            $healthStatus = $isOnline ? 'online' : ((int) ($statusResult['statusCode'] ?? 0) === 401 ? 'auth_error' : 'offline');

            $door->update([
                'status' => $connStatus,
                'connection_status' => $connStatus,
                'health_status' => $healthStatus,
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ]);

            $results[] = [
                'door_id' => $door->door_id,
                'is_online' => $isOnline,
                'status' => $connStatus,
                'health_status' => $healthStatus,
                'last_checked_at' => $door->last_checked_at->toIso8601String(),
            ];
        }

        return response()->json([
            'status' => 'success',
            'message' => "Audit konektivitas selesai untuk {$doors->count()} terminal pintu.",
            'total_audited' => $doors->count(),
            'online_count' => collect($results)->where('is_online', true)->count(),
            'data' => $results,
        ]);
    }
}
