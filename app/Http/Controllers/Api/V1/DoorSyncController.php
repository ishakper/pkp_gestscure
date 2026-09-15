<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDoorAccessJob;
use App\Models\ActivityLog;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Services\PortalAccess;
use Illuminate\Http\Request;

class DoorSyncController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess)
    {
    }

    public function sync(Request $request)
    {
        $this->authorizeDeviceManagement($request);
        $query = DoorAssignment::with('door');

        // If door_id filter is provided in request
        if ($request->filled('door_id')) {
            $query->whereHas('door', function ($q) use ($request) {
                $q->where('door_id', $request->door_id);
            });
        }

        // Only sync failed or pending assignments if specified
        if ($request->get('status') === 'failed') {
            $query->where('sync_status', 'failed');
        } elseif ($request->get('status') === 'pending') {
            $query->where('sync_status', 'pending');
        } else {
            $query->whereIn('sync_status', ['pending', 'failed']);
        }

        $actor = $request->user();
        if ($actor->isBuildingAdmin()) {
            $buildingId = $actor->employee?->building_id;
            abort_unless($buildingId || $actor->assigned_building, 403);
            $query->whereHas('door', fn ($doors) => $doors
                ->when($buildingId, fn ($scoped) => $scoped->where('building_id', $buildingId))
                ->when($actor->assigned_building, fn ($scoped) => $scoped->orWhere('location', $actor->assigned_building)));
        }
        $assignments = $query->get();
        $assignments->each(fn (DoorAssignment $assignment) => $this->authorize('physicalControl', $assignment->door));
        $dispatchedCount = 0;

        foreach ($assignments as $assignment) {
            $assignment->update(['sync_status' => 'pending']);
            SyncDoorAccessJob::dispatch($assignment->id);
            $dispatchedCount++;
        }

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'batch_sync_triggered',
            'description' => "Manual sync triggered for {$dispatchedCount} door assignments",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Proses sinkronisasi berhasil di-queue untuk {$dispatchedCount} hak akses",
            'dispatched_count' => $dispatchedCount,
        ]);
    }

    public function assignDoors(Request $request)
    {
        $this->authorizeDeviceManagement($request);
        $doorInputs = $request->input('door_ids') ?? $request->input('door_id');
        if (!$doorInputs) {
            return response()->json([
                'status' => 'error',
                'code' => 422,
                'message' => 'Parameter door_id atau door_ids wajib diisi',
            ], 422);
        }

        $empIdentifier = $request->input('employee_id') ?? $request->input('user_id') ?? $request->input('id');
        if (!$empIdentifier) {
            return response()->json([
                'status' => 'error',
                'code' => 422,
                'message' => 'Parameter identifier (employee_id, user_id, atau id) wajib diisi',
            ], 422);
        }

        $employee = \App\Models\Employee::where('id', $empIdentifier)
            ->orWhere('employee_id', $empIdentifier)
            ->orWhere('employee_id', 'USR-' . $empIdentifier)
            ->firstOrFail();

        $doorArray = is_array($doorInputs) ? $doorInputs : [$doorInputs];
        $doors = collect($doorArray)->map(function ($doorInput) {
            return Door::where('door_id', $doorInput)->orWhere('id', $doorInput)->firstOrFail();
        });
        $doors->each(fn (Door $door) => $this->authorize('physicalControl', $door));
        $assignedDoors = [];

        foreach ($doors as $door) {
                $assignment = DoorAssignment::updateOrCreate(
                    ['employee_id' => $employee->id, 'door_id' => $door->id],
                    ['sync_status' => 'pending', 'sync_attempts' => 0]
                );

                SyncDoorAccessJob::dispatch($assignment->id);
                $assignedDoors[] = $door->door_id;

                ActivityLog::create([
                    'admin_id' => $request->user()->id ?? null,
                    'action' => 'assign_door_access',
                    'subject_type' => 'DoorAssignment',
                    'subject_id' => $assignment->id,
                    'description' => "Assigned access to {$door->name} ({$door->door_id}) for employee {$employee->name}",
                    'timestamp' => now(),
                ]);
        }

        return response()->json([
            'status' => 'success',
            'message' => "Hak akses pintu (" . implode(', ', $assignedDoors) . ") berhasil diberikan. Sinkronisasi ke perangkat sedang diproses.",
            'data' => [
                'employee_id' => $employee->employee_id,
                'door_id' => $assignedDoors[0] ?? null,
                'assigned_doors' => $assignedDoors,
                'sync_status' => 'pending',
            ],
        ]);
    }

    public function lookup(Request $request)
    {
        $admin = $request->user();
        $query = \App\Models\Door::query();

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

    public function revokeDoors(Request $request)
    {
        $this->authorizeDeviceManagement($request);
        $empIdentifier = $request->input('employee_id') ?? $request->input('user_id') ?? $request->input('id');
        if (!$empIdentifier) {
            return response()->json([
                'status' => 'error',
                'code' => 422,
                'message' => 'Parameter identifier (employee_id, user_id, atau id) wajib diisi',
            ], 422);
        }

        $employee = \App\Models\Employee::where('id', $empIdentifier)
            ->orWhere('employee_id', $empIdentifier)
            ->orWhere('employee_id', 'USR-' . $empIdentifier)
            ->firstOrFail();

        $doorInput = $request->input('door_id') ?? $request->input('door_ids');

        $query = DoorAssignment::with('door')->where('employee_id', $employee->id);

        if ($doorInput) {
            $doorArray = is_array($doorInput) ? $doorInput : [$doorInput];
            $doorDbIds = \App\Models\Door::whereIn('door_id', $doorArray)
                ->orWhereIn('id', $doorArray)
                ->pluck('id');
            $query->whereIn('door_id', $doorDbIds);
        }

        $assignments = $query->get();
        $assignments->each(fn (DoorAssignment $assignment) => $this->authorize('physicalControl', $assignment->door));
        $deletedCount = DoorAssignment::whereKey($assignments->modelKeys())->delete();

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'revoke_door_access',
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'description' => "Revoked door access for employee {$employee->name} ({$employee->employee_id})",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Hak akses pintu untuk {$employee->name} berhasil dicabut ({$deletedCount} izin pintu).",
            'revoked_count' => $deletedCount,
        ]);
    }

    private function authorizeDeviceManagement(Request $request): void
    {
        abort_unless($request->user() && $this->portalAccess->can($request->user(), 'device.manage'), 403);
    }
}
