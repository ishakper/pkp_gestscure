<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoorResource;
use App\Models\ActivityLog;
use App\Models\Door;
use App\Services\HikvisionIsapiService;
use Illuminate\Http\Request;

class AdminDoorController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();
        $query = Door::withCount(['employees', 'doorAssignments']);

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
            'connection_status' => 'required|in:online,offline',
            'is_manual_override' => 'nullable|boolean',
        ]);

        $door->update([
            'connection_status' => $request->connection_status,
            'is_manual_override' => $request->has('is_manual_override') ? (bool) $request->is_manual_override : true,
            'last_checked_at' => now(),
        ]);

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'override_door_status',
            'subject_type' => 'Door',
            'subject_id' => $door->id,
            'description' => "Manual status override for {$door->door_id} ({$door->door_name}) set to {$request->connection_status}",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Status pintu {$door->door_id} berhasil diperbarui",
            'data' => new DoorResource($door),
        ]);
    }

    /**
     * Audit single physical terminal connectivity via ISAPI getDeviceStatus
     */
    public function checkConnection(Request $request, $door_id, HikvisionIsapiService $isapiService)
    {
        $door = Door::where('door_id', $door_id)->orWhere('id', $door_id)->firstOrFail();

        $statusResult = $isapiService->getDeviceStatus($door);
        $isOnline = (bool) ($statusResult['status'] ?? false);
        $connStatus = $isOnline ? 'online' : 'offline';

        $door->update([
            'status' => $connStatus,
            'connection_status' => $connStatus,
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
            'data' => new DoorResource($door->fresh()),
            'isapi_details' => $statusResult['data'] ?? null,
        ]);
    }

    /**
     * Audit all physical terminals connectivity via ISAPI
     */
    public function checkAllConnections(Request $request, HikvisionIsapiService $isapiService)
    {
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

            $door->update([
                'status' => $connStatus,
                'connection_status' => $connStatus,
                'is_manual_override' => false,
                'last_checked_at' => now(),
            ]);

            $results[] = [
                'door_id' => $door->door_id,
                'is_online' => $isOnline,
                'status' => $connStatus,
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
