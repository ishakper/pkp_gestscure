<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AssignDoorAccessRequest;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Http\Resources\EmployeeResource;
use App\Jobs\SyncDoorAccessJob;
use App\Models\ActivityLog;
use App\Models\BiometricStatus;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $admin = $request->user();
        $query = Employee::with(['biometricStatus', 'doors']);

        // Search filtering by name or NIK
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('nik', 'like', "%{$search}%");
            });
        }

        // Filter by door_id
        if ($request->filled('door_id')) {
            $doorId = $request->input('door_id');
            $query->whereHas('doors', function ($q) use ($doorId) {
                $q->where('doors.door_id', $doorId)
                  ->orWhere('doors.id', $doorId);
            });
        }

        // RBAC filtering if building_admin
        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $query->whereHas('doors', function ($q) use ($admin) {
                $q->where('location', $admin->assigned_building);
            });
        }

        $perPage = (int) $request->get('per_page', 10);
        $employees = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'pagination' => [
                'current_page' => $employees->currentPage(),
                'per_page' => $employees->perPage(),
                'total_records' => $employees->total(),
                'total_pages' => $employees->lastPage(),
            ],
            'data' => EmployeeResource::collection($employees),
        ]);
    }

    public function store(StoreEmployeeRequest $request)
    {
        $employeeId = $request->input('employee_id');
        if (!$employeeId) {
            $lastId = (Employee::withTrashed()->max('id') ?? 0) + 1;
            $employeeId = 'USR-' . (1000 + $lastId);
        }

        $employee = Employee::create([
            'employee_id' => $employeeId,
            'nik' => $request->nik,
            'name' => $request->name,
            'card_no' => $request->card_no,
            'department' => $request->department,
            'role' => $request->role ?? $request->role_jabatan ?? 'Staff',
            'role_jabatan' => $request->role_jabatan ?? $request->role ?? 'Staff',
        ]);

        $hasFp = (bool) $request->input('fingerprint_enrolled', false);
        $cardEnrolled = !empty($employee->card_no) || (bool) $request->input('card_enrolled', false);

        BiometricStatus::create([
            'employee_id' => $employee->id,
            'has_fingerprint' => $hasFp,
            'fingerprint_enrolled' => $hasFp,
            'card_enrolled' => $cardEnrolled,
            'biometric_template' => $hasFp ? 'BASE64_TEMPLATE_' . $employee->nik : null,
        ]);

        // Assign doors if door_ids passed
        if ($request->has('door_ids') && is_array($request->door_ids)) {
            $doors = Door::whereIn('door_id', $request->door_ids)->orWhereIn('id', $request->door_ids)->get();
            foreach ($doors as $door) {
                $assignment = DoorAssignment::create([
                    'employee_id' => $employee->id,
                    'door_id' => $door->id,
                    'sync_status' => 'pending',
                    'sync_attempts' => 0,
                ]);

                SyncDoorAccessJob::dispatch($assignment->id);
            }
        }

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'create_employee',
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'description' => "Created employee {$employee->name} ({$employee->employee_id})",
            'timestamp' => now(),
        ]);

        $employee->load(['biometricStatus', 'doors']);

        return response()->json([
            'status' => 'success',
            'message' => 'Karyawan berhasil ditambahkan',
            'data' => new EmployeeResource($employee),
        ], 201);
    }

    protected function findEmployeeByIdentifier($id): Employee
    {
        return Employee::where('id', $id)
            ->orWhere('employee_id', $id)
            ->firstOrFail();
    }

    public function show($id)
    {
        $employee = $this->findEmployeeByIdentifier($id)->load(['biometricStatus', 'doors']);
        
        $this->authorize('view', $employee);

        return response()->json([
            'status' => 'success',
            'data' => new EmployeeResource($employee),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, $id)
    {
        $employee = $this->findEmployeeByIdentifier($id)->load('biometricStatus');
        
        $this->authorize('update', $employee);

        $employee->update($request->only(['employee_id', 'nik', 'name', 'card_no', 'department', 'role', 'role_jabatan']));

        if ($employee->biometricStatus) {
            $hasFp = $request->has('fingerprint_enrolled') ? (bool) $request->fingerprint_enrolled : $employee->biometricStatus->has_fingerprint;
            $cardEnrolled = !empty($employee->card_no) || ($request->has('card_enrolled') ? (bool) $request->card_enrolled : $employee->biometricStatus->card_enrolled);

            $employee->biometricStatus->update([
                'has_fingerprint' => $hasFp,
                'fingerprint_enrolled' => $hasFp,
                'card_enrolled' => $cardEnrolled,
            ]);
        }

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'update_employee',
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'description' => "Updated employee {$employee->name} ({$employee->employee_id})",
            'timestamp' => now(),
        ]);

        $employee->load(['biometricStatus', 'doors']);

        return response()->json([
            'status' => 'success',
            'message' => 'Data karyawan berhasil diperbarui',
            'data' => new EmployeeResource($employee),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $employee = $this->findEmployeeByIdentifier($id);

        $this->authorize('delete', $employee);

        $employee->delete();

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'delete_employee',
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'description' => "Soft-deleted employee {$employee->name} ({$employee->employee_id})",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Karyawan berhasil dihapus',
        ]);
    }

    public function assignDoorAccess(AssignDoorAccessRequest $request, $id)
    {
        $employee = $this->findEmployeeByIdentifier($id);
        $door = Door::where('door_id', $request->door_id)
            ->orWhere('id', $request->door_id)
            ->firstOrFail();

        $this->authorize('assignDoor', [$employee, $door]);

        $assignment = DoorAssignment::updateOrCreate(
            ['employee_id' => $employee->id, 'door_id' => $door->id],
            ['sync_status' => 'pending', 'sync_attempts' => 0]
        );

        SyncDoorAccessJob::dispatch($assignment->id);

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'assign_door_access',
            'subject_type' => 'DoorAssignment',
            'subject_id' => $assignment->id,
            'description' => "Assigned access to {$door->door_name} ({$door->door_id}) for employee {$employee->name}",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Hak akses {$door->door_name} berhasil diberikan. Sinkronisasi ke perangkat sedang diproses.",
            'data' => [
                'employee_id' => $employee->employee_id,
                'door_id' => $door->door_id,
                'sync_status' => $assignment->sync_status,
            ],
        ]);
    }

    public function revokeDoorAccess(Request $request, $id, $door_id)
    {
        $employee = $this->findEmployeeByIdentifier($id);
        $door = Door::where('door_id', $door_id)
            ->orWhere('id', $door_id)
            ->firstOrFail();

        $this->authorize('assignDoor', [$employee, $door]);

        DoorAssignment::where('employee_id', $employee->id)
            ->where('door_id', $door->id)
            ->delete();

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'revoke_door_access',
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'description' => "Revoked access to {$door->door_name} ({$door->door_id}) for employee {$employee->name}",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Hak akses {$door->door_name} berhasil dicabut.",
        ]);
    }
}
