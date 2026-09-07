<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\DoorResource;
use App\Models\ActivityLog;
use App\Models\Door;
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
}
