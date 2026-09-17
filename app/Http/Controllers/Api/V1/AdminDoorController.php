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

        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $doors->where('location', $admin->assigned_building);
        }

        $scopedDoors = $doors->get();
        $doorIds = $scopedDoors->pluck('id');

        $totalUsers = Employee::count();
        $activeEmployees = Employee::where('employment_status', 'ACTIVE')->count();
        if ($activeEmployees === 0 && $totalUsers > 0) {
            $activeEmployees = $totalUsers;
        }

        $registeredCredentials = Employee::where(function ($q) {
            $q->whereNotNull('card_no')->where('card_no', '!=', '');
        })->orWhereHas('biometricStatus', function ($q) {
            $q->where('has_fingerprint', true)->orWhere('card_enrolled', true);
        })->count();

        return response()->json([
            'status' => 'success',
            'data' => [
                'totalUsers' => $totalUsers,
                'activeEmployees' => $activeEmployees,
                'registeredCredentials' => $registeredCredentials,
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
        $user = $request->user();
        $canView = $user && (
            $user->isSuperAdmin() ||
            $this->portalAccess->can($user, 'system.view') ||
            $this->portalAccess->can($user, 'security.view')
        );

        if (!$canView) {
            return response()->json([
                'status' => 'error',
                'message' => 'Unauthorized access to system health details.'
            ], 403);
        }

        // 1. App status
        $appStatus = [
            'status' => 'healthy',
            'app_name' => config('app.name', 'PKP SecureGate'),
            'environment' => config('app.env', 'production'),
            'server_time' => now()->toIso8601String(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];

        // 2. DB Connectivity
        $dbConnected = false;
        $dbDriver = 'unknown';
        try {
            \Illuminate\Support\Facades\DB::connection()->getPdo();
            $dbConnected = true;
            $dbDriver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        } catch (\Throwable $e) {
            $dbConnected = false;
        }

        $dbStatus = [
            'connected' => $dbConnected,
            'status' => $dbConnected ? 'connected' : 'disconnected',
            'driver' => $dbDriver,
        ];

        // 3. Doors & Hikvision Reachability
        $totalDoors = Door::count();
        $onlineDoors = Door::where('connection_status', 'online')->count();
        $primaryDoor = Door::where('door_id', 'DOOR-B')
            ->orWhere('device_ip', '192.168.90.15')
            ->first();

        $doorBInfo = null;
        if ($primaryDoor) {
            $doorBInfo = [
                'door_id' => $primaryDoor->door_id,
                'name' => $primaryDoor->name ?? $primaryDoor->door_name,
                'device_ip' => $primaryDoor->device_ip,
                'connection_status' => $primaryDoor->connection_status ?? 'offline',
                'health_status' => $primaryDoor->health_status ?? 'unknown',
                'last_checked_at' => $primaryDoor->last_checked_at ? $primaryDoor->last_checked_at->toIso8601String() : null,
            ];
        }

        // 4. Access Logs & Webhook Health
        $lastLog = AccessLog::latest('timestamp')->first();
        $lastLogTimestamp = $lastLog ? $lastLog->timestamp : null;

        $lastWebhookLog = AccessLog::where('source', 'HIKVISION')
            ->orWhereNotNull('isapi_event_type')
            ->latest('timestamp')
            ->first();
        if (!$lastWebhookLog) {
            $lastWebhookLog = $lastLog;
        }

        // 5. Queue Health
        $queueDriver = config('queue.default', 'sync');
        $failedJobsCount = 0;
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('failed_jobs')) {
                $failedJobsCount = \Illuminate\Support\Facades\DB::table('failed_jobs')->count();
            }
        } catch (\Throwable $e) {
            $failedJobsCount = 0;
        }

        $queueStatus = [
            'driver' => $queueDriver,
            'mode' => $queueDriver === 'sync' ? 'Sync Driver (Direct Execution)' : 'Queued Worker',
            'pending_jobs' => 0,
            'failed_jobs' => $failedJobsCount,
        ];

        return response()->json([
            'status' => 'success',
            'data' => [
                'app' => $appStatus,
                'database' => $dbStatus,
                'doors' => [
                    'total' => $totalDoors,
                    'online' => $onlineDoors,
                    'door_b' => $doorBInfo,
                ],
                'last_access_event' => [
                    'timestamp' => $lastLogTimestamp ? \Carbon\Carbon::parse($lastLogTimestamp)->toIso8601String() : null,
                    'employee_name' => $lastLog ? ($lastLog->employee_name ?? 'N/A') : null,
                    'access_status' => $lastLog ? ($lastLog->access_status ?? 'N/A') : null,
                ],
                'webhook' => [
                    'last_received' => ($lastWebhookLog && $lastWebhookLog->timestamp)
                        ? \Carbon\Carbon::parse($lastWebhookLog->timestamp)->toIso8601String()
                        : null,
                    'status' => 'active',
                ],
                'queue' => $queueStatus,
            ],
        ]);
    }
}
