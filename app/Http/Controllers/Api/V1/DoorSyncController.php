<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDoorAccessJob;
use App\Models\ActivityLog;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Services\PortalAccess;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class DoorSyncController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess)
    {
    }

    #[OA\Post(
        path: '/admin/door-assignments/sync',
        summary: 'Sinkronisasi Masal Hak Akses Pintu',
        description: 'Memicu job sinkronisasi ulang masal untuk hak akses pintu yang pending atau gagal.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'door_id', type: 'string', example: 'DOOR-001'),
                    new OA\Property(property: 'status', type: 'string', example: 'failed')
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Proses sinkronisasi berhasil di-queue',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Proses sinkronisasi berhasil di-queue untuk 3 hak akses',
                        'dispatched_count' => 3
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Forbidden')
        ]
    )]
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

    #[OA\Post(
        path: '/user-management/assign-doors',
        summary: 'Alokasi Pintu ke Karyawan',
        description: 'Mengalokasikan satu atau lebih pintu ke karyawan tertentu.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['employee_id', 'door_ids'],
                properties: [
                    new OA\Property(property: 'employee_id', type: 'string', example: 'USR-1001'),
                    new OA\Property(property: 'door_ids', type: 'array', items: new OA\Items(type: 'string'), example: ['DOOR-001', 'DOOR-002'])
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Hak akses pintu berhasil diberikan',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Hak akses pintu (DOOR-001, DOOR-002) berhasil diberikan. Sinkronisasi ke perangkat sedang diproses.',
                        'data' => [
                            'employee_id' => 'USR-1001',
                            'door_id' => 'DOOR-001',
                            'assigned_doors' => ['DOOR-001', 'DOOR-002'],
                            'sync_status' => 'pending'
                        ]
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Parameter tidak lengkap')
        ]
    )]
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

    #[OA\Post(
        path: '/user-management/revoke-doors',
        summary: 'Pencabutan Masal Hak Akses Pintu',
        description: 'Mencabut hak akses pintu dari karyawan.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['employee_id'],
                properties: [
                    new OA\Property(property: 'employee_id', type: 'string', example: 'USR-1001'),
                    new OA\Property(property: 'door_ids', type: 'array', items: new OA\Items(type: 'string'), example: ['DOOR-001'])
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pencabutan akses berhasil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Hak akses pintu untuk Budi Santoso berhasil dicabut (1 izin pintu).',
                        'revoked_count' => 1
                    ]
                )
            )
        ]
    )]
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

    #[OA\Post(
        path: '/user-management/bulk-access',
        summary: 'Pembaruan Akses Massal (Matriks Akses)',
        description: 'Memproses penambahan dan pencabutan hak akses pintu secara massal untuk multiple karyawan.',
        tags: ['Access Rights'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['changes'],
                properties: [
                    new OA\Property(
                        property: 'changes',
                        type: 'array',
                        items: new OA\Items(
                            properties: [
                                new OA\Property(property: 'employee_id', type: 'string', example: 'USR-1001'),
                                new OA\Property(property: 'door_id', type: 'string', example: 'DOOR-001'),
                                new OA\Property(property: 'action', type: 'string', enum: ['grant', 'revoke'], example: 'grant')
                            ]
                        )
                    )
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Eksekusi perubahan hak akses massal selesai',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Berhasil memproses 5 perubahan hak akses (5 sukses, 0 gagal).',
                        'processed_count' => 5,
                        'success_count' => 5,
                        'failed_count' => 0,
                        'results' => []
                    ]
                )
            ),
            new OA\Response(response: 422, description: 'Parameter tidak valid')
        ]
    )]
    public function bulkAccess(Request $request)
    {
        $this->authorizeDeviceManagement($request);

        $changes = $request->input('changes') ?? $request->input('grants') ?? [];
        if (!is_array($changes) || empty($changes)) {
            return response()->json([
                'status' => 'error',
                'code' => 422,
                'message' => 'Parameter changes atau grants wajib diisi dan berupa array non-kosong',
            ], 422);
        }

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($changes as $index => $item) {
            $empIdentifier = $item['employee_id'] ?? $item['user_id'] ?? $item['id'] ?? null;
            $doorIdentifier = $item['door_id'] ?? null;
            $action = strtolower($item['action'] ?? 'grant');

            if (!$empIdentifier || !$doorIdentifier) {
                $failedCount++;
                $results[] = [
                    'index' => $index,
                    'status' => 'failed',
                    'message' => 'employee_id dan door_id wajib diisi',
                ];
                continue;
            }

            try {
                $employee = \App\Models\Employee::where('id', $empIdentifier)
                    ->orWhere('employee_id', $empIdentifier)
                    ->orWhere('employee_id', 'USR-' . $empIdentifier)
                    ->firstOrFail();

                $door = Door::where('door_id', $doorIdentifier)
                    ->orWhere('id', $doorIdentifier)
                    ->firstOrFail();

                $this->authorize('physicalControl', $door);

                if ($action === 'grant' || $action === 'assign') {
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
                        'description' => "Bulk grant access to {$door->name} ({$door->door_id}) for employee {$employee->name}",
                        'timestamp' => now(),
                    ]);

                    $successCount++;
                    $results[] = [
                        'index' => $index,
                        'status' => 'success',
                        'action' => 'grant',
                        'employee_id' => $employee->employee_id,
                        'door_id' => $door->door_id,
                        'message' => "Hak akses {$door->door_id} diberikan ke {$employee->name}",
                    ];
                } elseif ($action === 'revoke') {
                    $assignment = DoorAssignment::where('employee_id', $employee->id)
                        ->where('door_id', $door->id)
                        ->first();

                    if ($assignment) {
                        $assignment->delete();

                        ActivityLog::create([
                            'admin_id' => $request->user()->id ?? null,
                            'action' => 'revoke_door_access',
                            'subject_type' => 'Employee',
                            'subject_id' => $employee->id,
                            'description' => "Bulk revoke access to {$door->name} ({$door->door_id}) for employee {$employee->name}",
                            'timestamp' => now(),
                        ]);

                        $successCount++;
                        $results[] = [
                            'index' => $index,
                            'status' => 'success',
                            'action' => 'revoke',
                            'employee_id' => $employee->employee_id,
                            'door_id' => $door->door_id,
                            'message' => "Hak akses {$door->door_id} dicabut dari {$employee->name}",
                        ];
                    } else {
                        $successCount++;
                        $results[] = [
                            'index' => $index,
                            'status' => 'success',
                            'action' => 'revoke',
                            'employee_id' => $employee->employee_id,
                            'door_id' => $door->door_id,
                            'message' => "Hak akses {$door->door_id} sudah tidak ada pada {$employee->name}",
                        ];
                    }
                } else {
                    $failedCount++;
                    $results[] = [
                        'index' => $index,
                        'status' => 'failed',
                        'message' => "Aksi tidak dikenal: {$action}",
                    ];
                }
            } catch (\Throwable $e) {
                $failedCount++;
                $results[] = [
                    'index' => $index,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'bulk_access_matrix_update',
            'description' => "Bulk access matrix update: {$successCount} succeeded, {$failedCount} failed out of " . count($changes) . " total items",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Berhasil memproses " . count($changes) . " perubahan hak akses ({$successCount} sukses, {$failedCount} gagal).",
            'processed_count' => count($changes),
            'success_count' => $successCount,
            'failed_count' => $failedCount,
            'results' => $results,
        ]);
    }

    private function authorizeDeviceManagement(Request $request): void
    {
        abort_unless($request->user() && $this->portalAccess->can($request->user(), 'device.manage'), 403);
    }
}
