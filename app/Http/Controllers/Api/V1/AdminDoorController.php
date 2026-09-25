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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use OpenApi\Attributes as OA;

class AdminDoorController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    #[OA\Get(
        path: '/admin/dashboard-metrics',
        summary: 'Metrik Dashboard Admin',
        description: 'Mendapatkan statistik ringkas pengguna, kredensial, perangkat pintu aktif, dan log akses.',
        tags: ['System Status'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Metrik berhasil diambil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => [
                            'totalUsers' => 50,
                            'activeEmployees' => 48,
                            'registeredCredentials' => 45,
                            'activeDoors' => 4,
                            'totalDoors' => 5,
                            'grantedLogs' => 120,
                            'deniedLogs' => 3
                        ]
                    ]
                )
            )
        ]
    )]
    public function metrics(Request $request)
    {
        $admin = $request->user();
        $doors = Door::query();

        if ($admin && $admin->isBuildingAdmin()) {
            $buildingId = $admin->employee?->building_id;
            abort_unless($buildingId || $admin->assigned_building, 403);
            $doors->where(fn ($query) => $query
                ->when($buildingId, fn ($scoped) => $scoped->where('building_id', $buildingId))
                ->when($admin->assigned_building, fn ($scoped) => $scoped->orWhere('location', $admin->assigned_building)));
        }

        $scopedDoors = $doors->get();
        $doorIds = $scopedDoors->pluck('id');

        $employees = Employee::query();
        if ($admin && $admin->isBuildingAdmin()) {
            $buildingId = $admin->employee?->building_id;
            abort_unless($buildingId || $admin->assigned_building, 403);
            $employees->where(fn ($query) => $query
                ->when($buildingId, fn ($scoped) => $scoped->where('building_id', $buildingId))
                ->when($admin->assigned_building, fn ($scoped) => $scoped->orWhereHas('doors', fn ($doors) => $doors->where('location', $admin->assigned_building))));
        }
        $totalUsers = (clone $employees)->count();
        $activeEmployeesQuery = (clone $employees)->where(function ($query) {
            $query->where('employment_status', 'ACTIVE')
                ->orWhereNull('employment_status')
                ->orWhere('employment_status', '');
        });
        $activeEmployees = (clone $activeEmployeesQuery)->count();

        // Registered means an active employee has a device/person identifier and
        // either a source-backed credential classification or legacy verified
        // card/biometric evidence. Door assignment is deliberately irrelevant.
        $registeredCredentials = (clone $activeEmployeesQuery)
            ->whereNotNull('hikvision_employee_no')
            ->where('hikvision_employee_no', '!=', '')
            ->where(function ($query) {
                $query->where(function ($classified) {
                    $classified->whereIn('credential_method', ['card', 'fingerprint'])
                        ->whereIn('credential_status', [
                            'confirmed_from_backup',
                            'expected_from_backup',
                            'verified',
                        ]);
                })->orWhere(fn ($legacy) => $legacy
                    ->whereNotNull('card_no')
                    ->where('card_no', '!=', '')
                )->orWhereHas('biometricStatus', fn ($biometric) => $biometric
                    ->where('has_fingerprint', true)
                    ->orWhere('card_enrolled', true)
                );
            })
            ->count();

        $fingerprintVerified = (clone $activeEmployeesQuery)
            ->where(fn ($query) => $query
                ->where('fingerprint_verified', true)
                ->orWhere('credential_status', 'verified'))
            ->count();
        $cardConfirmed = (clone $activeEmployeesQuery)
            ->where('credential_method', 'card')
            ->where('credential_status', 'confirmed_from_backup')
            ->where('fingerprint_verified', false)
            ->count();
        $fingerprintExpected = (clone $activeEmployeesQuery)
            ->where('credential_method', 'fingerprint')
            ->where('credential_status', 'expected_from_backup')
            ->where('fingerprint_verified', false)
            ->count();
        $reviewCredentials = (clone $activeEmployeesQuery)
            ->where(fn ($query) => $query
                ->where('credential_method', 'review')
                ->orWhere('credential_status', 'conflict'))
            ->count();
        $unknownCredentials = max(
            0,
            $activeEmployees
                - $cardConfirmed
                - $fingerprintExpected
                - $fingerprintVerified
                - $reviewCredentials
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'totalUsers' => $totalUsers,
                'activeEmployees' => $activeEmployees,
                'registeredCredentials' => $registeredCredentials,
                'credentialSummary' => [
                    'card' => $cardConfirmed,
                    'fingerprint_expected' => $fingerprintExpected,
                    'fingerprint_verified' => $fingerprintVerified,
                    'review' => $reviewCredentials,
                    'unknown' => $unknownCredentials,
                ],
                'activeDoors' => $scopedDoors->where('connection_status', 'online')->count(),
                'totalDoors' => $scopedDoors->count(),
                'grantedLogs' => AccessLog::whereIn('door_id', $doorIds)->where('access_status', 'Granted')->count(),
                'deniedLogs' => AccessLog::whereIn('door_id', $doorIds)->where('access_status', 'Denied')->count(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/admin/doors',
        summary: 'Daftar Perangkat Pintu',
        description: 'Mendapatkan daftar perangkat pintu beserta status koneksi dan statistik akses.',
        tags: ['Door'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar pintu berhasil diambil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'total_doors' => 1,
                        'data' => [
                            [
                                'id' => 1,
                                'door_id' => 'DOOR-001',
                                'name' => 'Pintu Utama Server',
                                'location' => 'Gedung Utama Lt 1',
                                'connection_status' => 'online'
                            ]
                        ]
                    ]
                )
            )
        ]
    )]
    public function index(Request $request)
    {
        $admin = $request->user();
        $query = Door::with('building:id,name')->withCount(['employees', 'doorAssignments', 'accessLogs']);

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

    #[OA\Get(
        path: '/user-management/doors-lookup',
        summary: 'Lookup Daftar Pintu',
        description: 'Mendapatkan daftar sederhana pintu untuk opsi dropdown.',
        tags: ['Door'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Lookup pintu berhasil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => [
                            ['id' => 1, 'door_id' => 'DOOR-001', 'name' => 'Pintu Utama Server', 'location' => 'Gedung Utama Lt 1']
                        ]
                    ]
                )
            )
        ]
    )]
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

    #[OA\Patch(
        path: '/admin/doors/{door_id}/status',
        summary: 'Override Status Maintenance Pintu',
        description: 'Mengaktifkan atau menonaktifkan mode maintenance manual pada pintu.',
        tags: ['Door'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'door_id', in: 'path', description: 'Kode Pintu', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['is_manual_override'],
                properties: [
                    new OA\Property(property: 'is_manual_override', type: 'boolean', example: true)
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Mode maintenance berhasil diperbarui'
            )
        ]
    )]
    public function overrideStatus(Request $request, $door_id)
    {
        $door = Door::where('door_id', $door_id)->orWhere('id', $door_id)->firstOrFail();

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

    #[OA\Post(
        path: '/admin/doors/{door_id}/open',
        summary: 'Remote Unlock Pintu (ISAPI Command)',
        description: 'Mengirimkan perintah remote unlock fisik ke terminal pintu via Hikvision ISAPI.',
        tags: ['Door'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'door_id', in: 'path', description: 'Kode Pintu', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Pintu berhasil dibuka via remote',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Pintu Pintu Utama Server (DOOR-001) berhasil dibuka via remote.'
                    ]
                )
            ),
            new OA\Response(response: 409, description: 'Remote unlock diblokir: terminal belum online'),
            new OA\Response(response: 500, description: 'Gagal membuka pintu (Device unreachable)')
        ]
    )]
    public function openDoor(Request $request, $door_id, HikvisionIsapiService $isapiService)
    {
        $door = Door::where('door_id', $door_id)->orWhere('id', $door_id)->firstOrFail();

        // Enforce fail-closed authorization: must have explicit permission, always
        $this->authorize('open', $door);

        $request->validate([
            'reason' => 'required|string|max:500',
        ], [
            'reason.required' => 'Alasan pembukaan pintu remote wajib diisi.',
            'reason.max' => 'Alasan pembukaan maksimal 500 karakter.',
        ]);

        // Normalize and validate reason: trim whitespace, reject whitespace-only
        $reason = trim($request->input('reason', ''));
        if (empty($reason)) {
            return response()->json([
                'status' => 'error',
                'code' => 422,
                'message' => 'Alasan pembukaan tidak boleh kosong atau hanya spasi.',
            ], 422);
        }

        $request->validate([
            'reason' => 'nullable|string|max:255',
        ]);

        if ($door->connection_status !== 'online' || $door->health_status === 'auth_error') {
            return response()->json([
                'status' => 'error',
                'code' => 409,
                'message' => 'Remote unlock diblokir: terminal belum terverifikasi online atau tidak aktif.',
            ], 409);
        }

        $idempotencyKey = trim((string) $request->header('X-Idempotency-Key'));
        if ($idempotencyKey !== '') {
            $existing = ActivityLog::where('action', 'remote_door_opened')
                ->where('description', 'like', "%Idempotency-Key: {$idempotencyKey}%")
                ->latest('id')->first();
            if ($existing) {
                return response()->json(['status' => 'success', 'message' => 'Remote unlock request already processed.'], 200);
            }
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
        $reason = $request->input('reason');
        $desc = "Remote unlock triggered for {$door->door_id} ({$doorName}) via web dashboard." . ($reason ? " Alasan: {$reason}" : '');

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'remote_door_opened',
            'subject_type' => 'Door',
            'subject_id' => $door->id,
            'description' => $desc,
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Pintu {$doorName} ({$door->door_id}) berhasil dibuka via remote.",
            'data' => new DoorResource($door->fresh()),
        ], 200);
    }

    #[OA\Post(
        path: '/admin/doors/{door_id}/check-connection',
        summary: 'Cek Koneksi Terminal Pintu',
        description: 'Melakukan verifikasi audit koneksi fisik terminal pintu tunggal via ISAPI.',
        tags: ['Door'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'door_id', in: 'path', description: 'Kode Pintu', required: true, schema: new OA\Schema(type: 'string'))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Hasil audit koneksi pintu',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Terminal pintu DOOR-001 (192.168.1.100) terhubung secara aktif.',
                        'is_online' => true,
                        'health_status' => 'online'
                    ]
                )
            )
        ]
    )]
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

    #[OA\Post(
        path: '/admin/doors/check-all',
        summary: 'Cek Koneksi Semua Terminal Pintu',
        description: 'Melakukan audit konektivitas masal untuk seluruh terminal pintu.',
        tags: ['Door'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Audit seluruh terminal pintu selesai',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'message' => 'Audit konektivitas selesai untuk 4 terminal pintu.',
                        'total_audited' => 4,
                        'online_count' => 4
                    ]
                )
            )
        ]
    )]
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

    #[OA\Get(
        path: '/admin/system-health',
        summary: 'Kesehatan & Monitoring Sistem',
        description: 'Mendapatkan status kesehatan aplikasi, database, konektivitas pintu, antrean, dan event log.',
        tags: ['System Status'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Metrik kesehatan sistem berhasil diambil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => [
                            'app' => ['status' => 'healthy', 'app_name' => 'PKP SecureGate'],
                            'database' => ['connected' => true, 'status' => 'connected'],
                            'doors' => ['total' => 4, 'online' => 4]
                        ]
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Unauthorized access')
        ]
    )]
    public function systemHealth(Request $request)
    {
        $admin = $request->user();
        abort_unless($admin && ($admin->isSuperAdmin() || $this->portalAccess->can($admin, 'system.view') || $this->portalAccess->can($admin, 'security.view')), 403);

        $serverTime = now();
        $doorFreshMinutes = max(1, (int) config('securegate_health.door_fresh_minutes', 15));
        $webhookFreshMinutes = max(1, (int) config('securegate_health.webhook_fresh_minutes', 15));
        $connected = false;

        try {
            DB::connection()->getPdo();
            $connected = true;
        } catch (\Throwable) {
            // Dependency state belongs in payload; health endpoint must remain readable.
        }

        $data = [
            'app' => ['app_name' => config('app.name', 'PKP SecureGate'), 'status' => $connected ? 'HEALTHY' : 'DEGRADED', 'server_time' => $serverTime->toIso8601String()],
            'database' => ['status' => $connected ? 'HEALTHY' : 'OFFLINE'],
            'primary_door' => ['status' => 'UNKNOWN', 'connection_status' => null, 'last_checked_at' => null, 'freshness_seconds' => null, 'stale' => null],
            'webhook' => ['status' => 'UNKNOWN', 'received_at' => null, 'event_at' => null, 'freshness_seconds' => null, 'stale' => null],
            'last_access_event' => null,
            'queue' => ['status' => 'UNKNOWN', 'pending_jobs' => null, 'failed_jobs' => null],
        ];

        if (!$connected) {
            return response()->json(['status' => 'success', 'data' => $data]);
        }

        $doors = Door::query();
        if ($admin->isBuildingAdmin()) {
            $buildingId = $admin->employee?->building_id;
            abort_unless($buildingId || $admin->assigned_building, 403);
            $doors->where(function ($query) use ($buildingId, $admin) {
                if ($buildingId) {
                    $query->where('building_id', $buildingId);
                    if ($admin->assigned_building) {
                        $query->orWhere(fn ($legacy) => $legacy->whereNull('building_id')->where('location', $admin->assigned_building));
                    }
                } else {
                    $query->whereNull('building_id')->where('location', $admin->assigned_building);
                }
            });
        }
        $scopedDoors = (clone $doors)->with('building:id,name')->get();
        $doorIds = $scopedDoors->pluck('id');
        $primaryDoor = $scopedDoors->firstWhere('door_id', 'DOOR-B');
        $classifyDoor = function ($door) use ($serverTime, $doorFreshMinutes): string {
            if (!$door->last_checked_at) return 'UNKNOWN';
            if ($door->last_checked_at->diffInSeconds($serverTime) > ($doorFreshMinutes * 60)) return 'STALE';

            return match (strtolower((string) $door->connection_status)) {
                'online' => 'HEALTHY',
                'offline' => 'OFFLINE',
                default => 'UNKNOWN',
            };
        };
        $summarize = function ($items) use ($classifyDoor): array {
            $statuses = $items->map($classifyDoor);
            $healthy = $statuses->filter(fn ($status) => $status === 'HEALTHY')->count();

            return [
                'total' => $items->count(),
                'online' => $healthy,
                'healthy' => $healthy,
                'offline' => $statuses->filter(fn ($status) => $status === 'OFFLINE')->count(),
                'stale' => $statuses->filter(fn ($status) => $status === 'STALE')->count(),
                'unknown' => $statuses->filter(fn ($status) => $status === 'UNKNOWN')->count(),
            ];
        };
        $data['doors'] = $summarize($scopedDoors);
        $data['buildings'] = $scopedDoors->groupBy(fn ($door) => $door->building_id ?: 'legacy:' . ($door->location ?: 'unknown'))->map(function ($items) use ($summarize) {
            $door = $items->first();
            return ['building_id' => $door->building_id, 'building_name' => $door->building?->name ?? $door->location] + $summarize($items);
        })->values();

        if ($primaryDoor) {
            $checkedAt = $primaryDoor->last_checked_at;
            $age = $checkedAt ? $checkedAt->diffInSeconds($serverTime) : null;
            $health = $classifyDoor($primaryDoor);
            $stale = in_array($health, ['STALE', 'UNKNOWN'], true);
            $connection = strtolower((string) $primaryDoor->connection_status);
            $data['primary_door'] = [
                'door_id' => $primaryDoor->door_id,
                'name' => $primaryDoor->door_name ?? $primaryDoor->name,
                'device_ip' => $primaryDoor->device_ip,
                'status' => $health,
                'connection_status' => $connection ?: null,
                'last_checked_at' => $checkedAt?->toIso8601String(),
                'freshness_seconds' => $age,
                'stale' => $stale,
            ];
        }

        $lastLog = AccessLog::query()->with('employee:id,name')->whereIn('door_id', $doorIds)->whereNotNull('timestamp')->latest('timestamp')->first();
        if ($lastLog) {
            $data['last_access_event'] = ['timestamp' => $lastLog->timestamp?->toIso8601String(), 'employee_name' => $lastLog->employee?->name, 'access_status' => $lastLog->access_status];
        }

        $lastWebhook = AccessLog::query()->whereIn('door_id', $doorIds)->where('source', 'HIKVISION_WEBHOOK')->latest('created_at')->first();
        if ($lastWebhook) {
            $receivedAt = $lastWebhook->created_at;
            $age = $receivedAt?->diffInSeconds($serverTime);
            $stale = $age === null || $age > ($webhookFreshMinutes * 60);
            $data['webhook'] = ['status' => $stale ? 'STALE' : 'ACTIVE', 'received_at' => $receivedAt?->toIso8601String(), 'event_at' => $lastWebhook->timestamp?->toIso8601String(), 'freshness_seconds' => $age, 'stale' => $stale];
        }

        $driver = config('queue.default');
        $pending = $driver === 'database' && Schema::hasTable('jobs') ? DB::table('jobs')->count() : null;
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        $oldestPendingAt = $pending && Schema::hasColumn('jobs', 'available_at')
            ? DB::table('jobs')->min('available_at')
            : null;
        $oldestPendingAge = $oldestPendingAt ? max(0, now()->timestamp - (int) $oldestPendingAt) : null;
        $queueStatus = ($failed ?? 0) > 0 || ($oldestPendingAge !== null && $oldestPendingAge > 300)
            ? 'DEGRADED'
            : ($driver === 'sync' ? 'HEALTHY' : 'UNKNOWN');
        $data['queue'] = ['status' => $queueStatus, 'pending_jobs' => $pending, 'failed_jobs' => $failed, 'oldest_pending_seconds' => $oldestPendingAge];
        $criticalStatuses = [$data['database']['status'], $data['primary_door']['status'], $data['webhook']['status']];
        $criticalUnhealthy = collect($criticalStatuses)->contains(fn ($status) => in_array($status, ['OFFLINE', 'DEGRADED', 'STALE', 'UNKNOWN'], true));
        $unhealthyDoors = $data['doors']['offline'] + $data['doors']['stale'] + $data['doors']['unknown'];
        $data['app']['status'] = $criticalUnhealthy || $unhealthyDoors > 0 || $data['queue']['status'] === 'DEGRADED' ? 'DEGRADED' : 'HEALTHY';

        return response()->json(['status' => 'success', 'data' => $data]);
    }
}
