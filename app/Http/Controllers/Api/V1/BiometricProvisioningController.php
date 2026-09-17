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
use OpenApi\Attributes as OA;

class BiometricProvisioningController extends Controller
{
    private function authorizeProvisioning(Request $request, ?Door $door = null): ?JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $role = strtolower((string) $actor->role);

        if (in_array($role, ['developer', 'devops', 'infra_admin'], true) && !$actor->isSuperAdmin()) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Technical roles (Developer/DevOps) are denied biometric provisioning access.',
            ], 403);
        }

        if (in_array($role, ['employee', 'intern'], true)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Employees and interns cannot self-provision biometric device credentials.',
            ], 403);
        }

        if (!$actor->isSuperAdmin() && !in_array($role, ['hrd', 'building_admin'], true)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Unauthorized. Only HRD, Super Admin, or Building Admin can provision door access.',
            ], 403);
        }

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

    #[OA\Post(
        path: '/user-management/employees/{id}/sync-biometric',
        summary: 'Sinkronisasi Profil Biometrik & Akses Pintu',
        description: 'Menyinkronkan profil karyawan, nomor kartu, dan hak akses pintu ke perangkat fisik.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'door_ids', type: 'array', items: new OA\Items(type: 'string'), example: ['DOOR-001']),
                    new OA\Property(property: 'mode', type: 'string', example: 'sync')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Profil biometrik berhasil disinkronkan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Profil biometrik dan hak akses berhasil disinkronkan ke 1 pintu.',
                        'data' => [
                            'employee' => ['id' => 1, 'employee_id' => 'USR-1001', 'name' => 'Budi Santoso', 'card_no' => 'CARD-99081'],
                            'synced_doors' => [['door_id' => 'DOOR-001', 'status' => 'synced']],
                            'failed_doors' => []
                        ]
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Access Denied'),
            new OA\Response(response: 422, description: 'Karyawan belum memiliki penugasan pintu')
        ]
    )]
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
        if ($admin->isBuildingAdmin() && !$admin->can('update', $employee)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Anda tidak memiliki akses ke karyawan di gedung ini.',
            ], 403);
        }

        $doorInputs = $request->input('door_ids') ?? $request->input('door_id');
        $doorsQuery = Door::query();

        if (!empty($doorInputs)) {
            $doorArray = is_array($doorInputs) ? $doorInputs : [$doorInputs];
            $doorsQuery->where(function ($q) use ($doorArray) {
                $q->whereIn('door_id', $doorArray)->orWhereIn('id', $doorArray);
            });
        } else {
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

    #[OA\Post(
        path: '/admin/doors/{door_id}/sync-employee/{employee_id}',
        summary: 'Sinkronisasi Karyawan ke Pintu Tunggal',
        description: 'Sinkronisasi langsung biometrik dan kredensial karyawan ke satu terminal pintu fisik.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'door_id', in: 'path', description: 'Kode Pintu', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'employee_id', in: 'path', description: 'ID Karyawan', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Berhasil disinkronkan ke pintu',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Profil dan kredensial untuk Budi Santoso berhasil disinkronkan ke DOOR-001',
                        'data' => ['door_id' => 'DOOR-001', 'employee_id' => 'USR-1001', 'sync_status' => 'synced']
                    ]
                )
            ),
            new OA\Response(response: 502, description: 'Gagal provisioning ke perangkat hardware')
        ]
    )]
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
        if ($admin->isBuildingAdmin() && !$admin->can('update', $employee)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Anda tidak memiliki akses ke karyawan di gedung ini.',
            ], 403);
        }

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

    #[OA\Get(
        path: '/user-management/employees/{id}/door-sync-status',
        summary: 'Status Sinkronisasi Pintu Karyawan',
        description: 'Mendapatkan status sinkronisasi pintu dan biometrik karyawan.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', description: 'ID internal atau Employee ID', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status sinkronisasi ditemukan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => [
                            'employee' => ['id' => 1, 'employee_id' => 'USR-1001', 'name' => 'Budi Santoso'],
                            'assignments' => [
                                ['assignment_id' => 1, 'door_id' => 'DOOR-001', 'sync_status' => 'synced']
                            ]
                        ]
                    ]
                )
            )
        ]
    )]
    public function getEmployeeSyncStatus(Request $request, $id): JsonResponse
    {
        $actor = $request->user();
        if (!$actor) {
            return response()->json(['status' => 'error', 'message' => 'Unauthenticated.'], 401);
        }

        $role = strtolower((string) $actor->role);

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

        if ($actor->isBuildingAdmin() && !$actor->can('view', $employee)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Anda tidak memiliki akses ke karyawan di gedung ini.',
            ], 403);
        }

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
