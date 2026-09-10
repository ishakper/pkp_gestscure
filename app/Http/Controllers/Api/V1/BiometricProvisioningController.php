<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDoorAccessJob;
use App\Models\ActivityLog;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BiometricProvisioningController extends Controller
{
    /**
     * Common authorization check for biometric provisioning.
     */
    private function authorizeProvisioning(Request $request, ?Door $door = null): ?JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $role = strtolower((string) $actor->role);

        // 1. Technical roles (developer, devops, infra_admin) denied
        if (in_array($role, ['developer', 'devops', 'infra_admin'], true) && !$actor->isSuperAdmin()) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Technical roles (Developer/DevOps) are denied biometric provisioning access.',
            ], 403);
        }

        // 2. Employee and intern self-provisioning denied
        if (in_array($role, ['employee', 'intern'], true)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Employees and interns cannot self-provision biometric device credentials.',
            ], 403);
        }

        // 3. Only super_admin, hrd, and building_admin permitted
        if (!$actor->isSuperAdmin() && !in_array($role, ['hrd', 'building_admin'], true)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Unauthorized. Only HRD, Super Admin, or Building Admin can provision door access.',
            ], 403);
        }

        // 4. Building Admin cross-building scoping check
        if ($actor->isBuildingAdmin() && $actor->assigned_building && $door) {
            if ($door->location !== $actor->assigned_building) {
                return response()->json([
                    'status' => 'error',
                    'code' => 403,
                    'message' => 'Anda tidak memiliki akses ke perangkat di gedung ini.',
                ], 403);
            }
        }

        return null;
    }

    /**
     * Synchronize employee biometric profile, card credentials, and access rights
     * to selected or all assigned doors.
     */
    public function syncEmployeeBiometric(Request $request, $id, HikvisionIsapiService $isapiService): JsonResponse
    {
        if ($authError = $this->authorizeProvisioning($request)) {
            return $authError;
        }

        $employee = Employee::where('id', $id)
            ->orWhere('employee_id', $id)
            ->orWhere('employee_id', 'USR-' . $id)
            ->firstOrFail();

        $admin = $request->user();

        // 1. Resolve doors
        $doorInputs = $request->input('door_ids') ?? $request->input('door_id');
        $doorsQuery = Door::query();

        if (!empty($doorInputs)) {
            $doorArray = is_array($doorInputs) ? $doorInputs : [$doorInputs];
            $doorsQuery->where(function ($q) use ($doorArray) {
                $q->whereIn('door_id', $doorArray)->orWhereIn('id', $doorArray);
            });
        } else {
            // Default to all currently assigned doors
            $assignedDoorIds = DoorAssignment::where('employee_id', $employee->id)->pluck('door_id');
            if ($assignedDoorIds->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'code' => 422,
                    'message' => 'Karyawan belum memiliki penugasan pintu. Cantumkan parameter door_ids.',
                ], 422);
            }
            $doorsQuery->whereIn('id', $assignedDoorIds);
        }

        $doors = $doorsQuery->get();

        if ($doors->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'code' => 404,
                'message' => 'Tidak ada pintu yang sesuai untuk sinkronisasi.',
            ], 404);
        }

        // Strict cross-building check for building admin
        if ($admin->isBuildingAdmin() && $admin->assigned_building) {
            $crossBuildingDoors = $doors->filter(fn($d) => $d->location !== $admin->assigned_building);
            if ($crossBuildingDoors->isNotEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'code' => 403,
                    'message' => 'Building admin cannot sync doors in other buildings.',
                ], 403);
            }
        }

        $mode = strtolower((string) $request->input('mode', 'sync'));
        $syncedDoors = [];
        $failedDoors = [];

        foreach ($doors as $door) {
            $assignment = DoorAssignment::updateOrCreate(
                ['employee_id' => $employee->id, 'door_id' => $door->id],
                ['sync_type' => 'FULL']
            );

            if ($mode === 'async') {
                $assignment->update(['sync_status' => 'pending']);
                SyncDoorAccessJob::dispatch($assignment->id);

                $syncedDoors[] = [
                    'door_id' => $door->door_id,
                    'door_name' => $door->door_name ?? $door->name,
                    'status' => 'queued',
                    'message' => 'Sinkronisasi dijadwalkan di antrean latar belakang',
                ];
            } else {
                // Immediate Synchronous Provisioning
                $res = $isapiService->provisionEmployeeAccess($door, $employee, $request->only([
                    'userType', 'userVerifyMode', 'closeDelay', 'beginTime', 'endTime',
                ]));

                if ($res['status']) {
                    $assignment->update([
                        'sync_status' => 'synced',
                        'last_sync_error' => null,
                        'last_synced_at' => now(),
                        'user_info_synced_at' => now(),
                        'card_synced_at' => !empty($employee->card_no) ? now() : null,
                    ]);

                    $syncedDoors[] = [
                        'door_id' => $door->door_id,
                        'door_name' => $door->door_name ?? $door->name,
                        'status' => 'synced',
                        'user_info_synced' => true,
                        'card_synced' => !empty($employee->card_no),
                        'access_right_synced' => true,
                        'message' => $res['message'] ?? 'Berhasil diprovisioning',
                    ];
                } else {
                    $assignment->update([
                        'sync_status' => 'failed',
                        'last_sync_error' => $res['error'] ?? 'Provisioning failed',
                    ]);

                    $failedDoors[] = [
                        'door_id' => $door->door_id,
                        'door_name' => $door->door_name ?? $door->name,
                        'status' => 'failed',
                        'error' => $res['error'] ?? 'Provisioning failed',
                        'message' => $res['message'] ?? 'Gagal provisioning',
                    ];
                }
            }
        }

        // Audit Log - zero raw payload, zero card credentials
        ActivityLog::create([
            'admin_id' => $admin?->id,
            'action' => 'biometric_user_provisioning',
            'subject_type' => 'Employee',
            'subject_id' => $employee->id,
            'description' => "Biometric provisioning executed for {$employee->name} ({$employee->employee_id}): " .
                count($syncedDoors) . " synced, " . count($failedDoors) . " failed.",
            'timestamp' => now(),
        ]);

        $overallStatus = empty($failedDoors) ? 'success' : (empty($syncedDoors) ? 'error' : 'partial');
        $statusCode = $overallStatus === 'error' ? 502 : 200;

        return response()->json([
            'status' => $overallStatus,
            'message' => $overallStatus === 'success'
                ? "Profil biometrik dan hak akses berhasil disinkronkan ke " . count($syncedDoors) . " pintu."
                : ($overallStatus === 'partial'
                    ? "Sinkronisasi selesai dengan " . count($syncedDoors) . " berhasil dan " . count($failedDoors) . " gagal."
                    : "Gagal menyinkronkan profil ke semua pintu target."),
            'data' => [
                'employee' => [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'card_no' => $employee->card_no,
                ],
                'synced_doors' => $syncedDoors,
                'failed_doors' => $failedDoors,
            ],
        ], $statusCode);
    }

    /**
     * Direct endpoint to sync a single employee's biometric profile to a specific door.
     */
    public function syncDoorEmployee(Request $request, $door_id, $employee_id, HikvisionIsapiService $isapiService): JsonResponse
    {
        $door = Door::where('door_id', $door_id)->orWhere('id', $door_id)->firstOrFail();

        if ($authError = $this->authorizeProvisioning($request, $door)) {
            return $authError;
        }

        $employee = Employee::where('id', $employee_id)
            ->orWhere('employee_id', $employee_id)
            ->orWhere('employee_id', 'USR-' . $employee_id)
            ->firstOrFail();

        $admin = $request->user();

        $assignment = DoorAssignment::updateOrCreate(
            ['employee_id' => $employee->id, 'door_id' => $door->id],
            ['sync_type' => 'FULL']
        );

        $result = $isapiService->provisionEmployeeAccess($door, $employee, $request->only([
            'userType', 'userVerifyMode', 'closeDelay', 'beginTime', 'endTime',
        ]));

        if ($result['status']) {
            $assignment->update([
                'sync_status' => 'synced',
                'last_sync_error' => null,
                'last_synced_at' => now(),
                'user_info_synced_at' => now(),
                'card_synced_at' => !empty($employee->card_no) ? now() : null,
            ]);

            ActivityLog::create([
                'admin_id' => $admin?->id,
                'action' => 'biometric_single_door_provisioning',
                'subject_type' => 'DoorAssignment',
                'subject_id' => $assignment->id,
                'description' => "Provisioned biometric profile for {$employee->name} ({$employee->employee_id}) on Door {$door->door_id}",
                'timestamp' => now(),
            ]);

            return response()->json([
                'status' => 'success',
                'message' => "Profil dan kredensial untuk {$employee->name} berhasil disinkronkan ke {$door->door_id}",
                'data' => [
                    'door_id' => $door->door_id,
                    'employee_id' => $employee->employee_id,
                    'sync_status' => 'synced',
                    'user_info_synced_at' => $assignment->user_info_synced_at,
                    'card_synced_at' => $assignment->card_synced_at,
                    'steps' => $result['steps'] ?? [],
                ],
            ]);
        }

        $assignment->update([
            'sync_status' => 'failed',
            'last_sync_error' => $result['error'] ?? 'Provisioning failed',
        ]);

        return response()->json([
            'status' => 'error',
            'code' => $result['statusCode'] ?? 502,
            'message' => $result['message'] ?? 'Gagal melakukan provisioning ke perangkat.',
            'error' => $result['error'] ?? 'Unknown device error',
            'data' => [
                'door_id' => $door->door_id,
                'employee_id' => $employee->employee_id,
                'sync_status' => 'failed',
                'steps' => $result['steps'] ?? [],
            ],
        ], $result['statusCode'] ?? 502);
    }

    /**
     * Get door synchronization and biometric status for an employee.
     */
    public function getEmployeeSyncStatus(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $role = strtolower((string) $actor->role);

        // Technical roles denied
        if (in_array($role, ['developer', 'devops', 'infra_admin'], true) && !$actor->isSuperAdmin()) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Technical roles (Developer/DevOps) are denied biometric provisioning access.',
            ], 403);
        }

        $employee = Employee::where('id', $id)
            ->orWhere('employee_id', $id)
            ->orWhere('employee_id', 'USR-' . $id)
            ->firstOrFail();

        // Employee / Intern can only view self
        if (in_array($role, ['employee', 'intern'], true)) {
            if ($actor->employee_id && $actor->employee_id !== $employee->id) {
                return response()->json([
                    'status' => 'error',
                    'code' => 403,
                    'message' => 'Access denied. You may only view your own sync status.',
                ], 403);
            }
        }

        $assignments = DoorAssignment::with('door')
            ->where('employee_id', $employee->id)
            ->get()
            ->map(function ($a) {
                return [
                    'assignment_id' => $a->id,
                    'door_id' => $a->door?->door_id,
                    'door_name' => $a->door?->door_name ?? $a->door?->name,
                    'door_ip' => $a->door?->device_ip,
                    'sync_status' => $a->sync_status,
                    'sync_attempts' => $a->sync_attempts,
                    'last_sync_error' => $a->last_sync_error,
                    'last_synced_at' => $a->last_synced_at?->toIso8601String(),
                    'user_info_synced_at' => $a->user_info_synced_at?->toIso8601String(),
                    'card_synced_at' => $a->card_synced_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'status' => 'success',
            'data' => [
                'employee' => [
                    'id' => $employee->id,
                    'employee_id' => $employee->employee_id,
                    'name' => $employee->name,
                    'card_no' => $employee->card_no,
                ],
                'assignments' => $assignments,
            ],
        ]);
    }
}
