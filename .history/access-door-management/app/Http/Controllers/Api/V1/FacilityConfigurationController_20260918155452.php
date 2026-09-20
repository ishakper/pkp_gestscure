<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\Door;
use App\Models\Zone;
use App\Services\HikvisionIsapiService;
use App\Services\PortalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class FacilityConfigurationController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    #[OA\Get(
        path: '/admin/buildings',
        summary: 'Daftar Gedung Beserta Zona & Pintu',
        description: 'Mendapatkan daftar hirarki gedung beserta zona dan terminal pintu yang ada di dalamnya.',
        tags: ['Facility Configuration'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar gedung berhasil diambil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => [
                            ['id' => 1, 'code' => 'HQ', 'name' => 'Gedung Utama', 'zones' => [], 'doors' => []]
                        ]
                    ]
                )
            )
        ]
    )]
    public function buildings(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'organization.view');
        $actor = $request->user();
        $buildings = Building::query()->with([
            'zones' => fn ($query) => $query->orderBy('name'),
            'zones.doors' => fn ($query) => $query->orderBy('name'),
        ]);
        if ($actor->isBuildingAdmin()) {
            $buildings->where(function ($query) use ($actor) {
                $query->whereHas('employees', fn ($employees) => $employees->whereKey($actor->employee_id));
                if ($actor->assigned_building) {
                    $query->orWhere('name', $actor->assigned_building)
                        ->orWhereHas('doors', fn ($doors) => $doors->where('location', $actor->assigned_building));
                }
            });
        }
        return response()->json(['status' => 'success', 'data' => $buildings->orderBy('name')->get(['id', 'code', 'name', 'description', 'is_active'])]);
    }

    #[OA\Post(
        path: '/admin/buildings',
        summary: 'Tambah Gedung Fasilitas',
        description: 'Mendaftarkan gedung baru ke dalam konfigurasi fasilitas.',
        tags: ['Facility Configuration'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'BLD-A'),
                    new OA\Property(property: 'name', type: 'string', example: 'Gedung A (R&D Center)'),
                    new OA\Property(property: 'description', type: 'string', example: 'Pusat Riset dan Pengembangan')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Gedung berhasil didaftarkan'),
            new OA\Response(response: 422, description: 'Validasi gagal / Kode sudah ada')
        ]
    )]
    public function storeBuilding(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'organization.manage');
        $data = $request->validate([
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:buildings,code'],
            'name' => ['required', 'string', 'max:255', 'unique:buildings,name'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $building = Building::create([...$data, 'code' => strtoupper($data['code']), 'is_active' => true]);
        $this->audit($request, 'building_created', 'Building', $building->id, "Building {$building->code} registered");
        return response()->json(['status' => 'success', 'data' => $building], 201);
    }

    #[OA\Post(
        path: '/admin/zones',
        summary: 'Tambah Zona Fasilitas',
        description: 'Mendaftarkan zona area baru dalam suatu gedung.',
        tags: ['Facility Configuration'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['building_id', 'code', 'name'],
                properties: [
                    new OA\Property(property: 'building_id', type: 'integer', example: 1),
                    new OA\Property(property: 'code', type: 'string', example: 'ZONE-RESTRICTED'),
                    new OA\Property(property: 'name', type: 'string', example: 'Zona Terbatas Server Room')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Zona berhasil didaftarkan')
        ]
    )]
    public function storeZone(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'organization.manage');
        $data = $request->validate([
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'code' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+$/', 'unique:zones,code'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $zone = Zone::create([...$data, 'code' => strtoupper($data['code']), 'is_active' => true]);
        $this->audit($request, 'zone_created', 'Zone', $zone->id, "Zone {$zone->code} registered for Building #{$zone->building_id}");
        return response()->json(['status' => 'success', 'data' => $zone], 201);
    }

    #[OA\Post(
        path: '/admin/doors',
        summary: 'Registrasi Perangkat Pintu Baru',
        description: 'Mendaftarkan terminal hardware pintu baru ke dalam sistem.',
        tags: ['Facility Configuration'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['door_id', 'name', 'building_id', 'device_ip', 'device_model'],
                properties: [
                    new OA\Property(property: 'door_id', type: 'string', example: 'DOOR-002'),
                    new OA\Property(property: 'name', type: 'string', example: 'Pintu Ruang Server Lt 2'),
                    new OA\Property(property: 'building_id', type: 'integer', example: 1),
                    new OA\Property(property: 'zone_id', type: 'integer', example: 1),
                    new OA\Property(property: 'device_ip', type: 'string', format: 'ipv4', example: '192.168.1.101'),
                    new OA\Property(property: 'gateway', type: 'string', format: 'ipv4', example: '192.168.1.1'),
                    new OA\Property(property: 'device_model', type: 'string', example: 'DS-K1T804AMF')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Terminal berhasil didaftarkan')
        ]
    )]
    public function storeDoor(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'device.manage');
        $data = $this->validateDoor($request);
        $building = Building::findOrFail($data['building_id']);
        $door = Door::create($this->doorPayload($data, $building) + [
            'status' => 'offline', 'connection_status' => 'offline', 'is_manual_override' => false,
        ]);
        $this->audit($request, 'door_created', 'Door', $door->id, "Door {$door->door_id} registered for {$building->name}");
        return response()->json(['status' => 'success', 'message' => 'Terminal registered offline pending connectivity verification.', 'data' => $this->doorData($door)], 201);
    }

    #[OA\Put(
        path: '/admin/doors/{door_id}',
        summary: 'Perbarui Konfigurasi Pintu',
        description: 'Memperbarui data alamat IP, nama, atau lokasi gedung/zona pintu.',
        tags: ['Facility Configuration'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'door_id', in: 'path', description: 'Kode Pintu', required: true, schema: new OA\Schema(type: 'string'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'door_id', type: 'string', example: 'DOOR-002'),
                    new OA\Property(property: 'name', type: 'string', example: 'Pintu Ruang Server Lt 2 Revision'),
                    new OA\Property(property: 'building_id', type: 'integer', example: 1),
                    new OA\Property(property: 'device_ip', type: 'string', format: 'ipv4', example: '192.168.1.101'),
                    new OA\Property(property: 'device_model', type: 'string', example: 'DS-K1T804AMF')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Konfigurasi pintu berhasil diperbarui')
        ]
    )]
    public function updateDoor(Request $request, string $doorId): JsonResponse
    {
        $this->authorizePermission($request, 'device.manage');
        $door = Door::where('door_id', $doorId)->orWhere('id', $doorId)->firstOrFail();
        $data = $this->validateDoor($request, $door);
        $building = Building::findOrFail($data['building_id']);
        $door->update($this->doorPayload($data, $building));
        $this->audit($request, 'door_updated', 'Door', $door->id, "Door {$door->door_id} configuration updated for {$building->name}");
        return response()->json(['status' => 'success', 'data' => $this->doorData($door->fresh())]);
    }

    #[OA\Post(
        path: '/admin/doors/test-connection',
        summary: 'Uji Konektivitas Terminal ISAPI',
        description: 'Melakukan tes koneksi read-only GET /ISAPI/System/deviceInfo ke terminal pintu fisik sebelum onboarding.',
        tags: ['Facility Configuration'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['device_ip'],
                properties: [
                    new OA\Property(property: 'device_ip', type: 'string', format: 'ipv4', example: '192.168.90.16'),
                    new OA\Property(property: 'isapi_username', type: 'string', example: 'admin'),
                    new OA\Property(property: 'isapi_password', type: 'string', example: 'Secret123')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Koneksi terverifikasi'),
            new OA\Response(response: 422, description: 'Gagal terhubung ke terminal')
        ]
    )]
    public function testDoorConnection(Request $request, HikvisionIsapiService $isapiService): JsonResponse
    {
        $this->authorizePermission($request, 'device.manage');

        $data = $request->validate([
            'device_ip' => ['required', 'ip'],
            'isapi_username' => ['nullable', 'string', 'max:100'],
            'isapi_password' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $isapiService->testDeviceConnection(
            $data['device_ip'],
            $data['isapi_username'] ?? null,
            $data['isapi_password'] ?? null
        );

        if ($result['status']) {
            return response()->json([
                'status' => 'success',
                'message' => 'Koneksi ke terminal ISAPI berhasil diverifikasi.',
                'data' => $result['data'],
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => $result['error'] ?? 'Gagal terhubung ke terminal pintu fisik.',
            'statusCode' => $result['statusCode'] ?? 500,
        ], 422);
    }

    #[OA\Post(
        path: '/admin/doors/onboard',
        summary: 'Wizard Onboard Terminal Pintu Baru',
        description: 'Mendaftarkan terminal pintu baru dengan verifikasi otomatis koneksi ISAPI dan enkripsi kredensial.',
        tags: ['Facility Configuration'],
        security: [['sanctum' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['door_id', 'name', 'building_id', 'device_ip', 'isapi_username', 'isapi_password'],
                properties: [
                    new OA\Property(property: 'door_id', type: 'string', example: 'DOOR-003'),
                    new OA\Property(property: 'name', type: 'string', example: 'Pintu Server R&D Lt 2'),
                    new OA\Property(property: 'building_id', type: 'integer', example: 1),
                    new OA\Property(property: 'floor_id', type: 'integer', example: 1),
                    new OA\Property(property: 'zone_id', type: 'integer', example: 1),
                    new OA\Property(property: 'device_ip', type: 'string', format: 'ipv4', example: '192.168.90.16'),
                    new OA\Property(property: 'gateway', type: 'string', format: 'ipv4', example: '192.168.90.1'),
                    new OA\Property(property: 'device_model', type: 'string', example: 'DS-K1T804AMF'),
                    new OA\Property(property: 'isapi_username', type: 'string', example: 'admin'),
                    new OA\Property(property: 'isapi_password', type: 'string', example: 'Secret123')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Device onboarded successfully'),
            new OA\Response(response: 422, description: 'Validation or connectivity failure')
        ]
    )]
    public function onboardDoor(Request $request, HikvisionIsapiService $isapiService): JsonResponse
    {
        $this->authorizePermission($request, 'device.manage');

        $data = $request->validate([
            'door_id' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('doors', 'door_id')],
            'name' => ['required', 'string', 'max:120'],
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'floor_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer', Rule::exists('zones', 'id')->where(fn ($query) => $query->where('building_id', $request->integer('building_id')))],
            'device_ip' => ['required', 'ip', Rule::unique('doors', 'device_ip')],
            'gateway' => ['nullable', 'ip'],
            'device_model' => ['nullable', 'string', 'max:120'],
            'isapi_username' => ['required', 'string', 'max:100'],
            'isapi_password' => ['required', 'string', 'max:255'],
        ]);

        $building = Building::findOrFail($data['building_id']);

        // 1. Run read-only SystemDeviceInfo connection test
        $testResult = $isapiService->testDeviceConnection(
            $data['device_ip'],
            $data['isapi_username'],
            $data['isapi_password']
        );

        $isOnline = (bool) ($testResult['status'] ?? false);
        $connStatus = $isOnline ? 'online' : 'offline';
        $healthStatus = $isOnline ? 'online' : 'offline';

        $deviceModel = $data['device_model'] 
            ?? ($testResult['data']['model'] ?? 'DS-K1T804AMF');
        $serialNumber = $testResult['data']['serialNumber'] ?? null;
        $firmwareVersion = $testResult['data']['firmware'] ?? null;

        // 2. Door model handles single encryption boundary via 'isapi_password' => 'encrypted' cast
        // 3. Save Door record to database
        $door = Door::create([
            'door_id' => strtoupper($data['door_id']),
            'name' => $data['name'],
            'door_name' => $data['name'],
            'building_id' => $building->id,
            'floor_id' => $data['floor_id'] ?? null,
            'zone_id' => $data['zone_id'] ?? null,
            'location' => $building->name,
            'device_ip' => $data['device_ip'],
            'ip_address' => $data['device_ip'],
            'gateway' => $data['gateway'] ?? '192.168.90.1',
            'model' => $deviceModel,
            'device_model' => $deviceModel,
            'serial_number' => $serialNumber,
            'firmware_version' => $firmwareVersion,
            'isapi_username' => $data['isapi_username'],
            'isapi_password' => $data['isapi_password'],
            'status' => $connStatus,
            'connection_status' => $connStatus,
            'health_status' => $healthStatus,
            'is_manual_override' => false,
            'last_checked_at' => now(),
        ]);

        // 4. Record Audit Trail
        $this->audit(
            $request,
            'device_onboarded',
            'Door',
            $door->id,
            "Onboarded device {$door->door_id} ({$door->device_ip}) for {$building->name} with status {$connStatus}"
        );

        return response()->json([
            'status' => 'success',
            'message' => "Terminal {$door->door_id} ({$door->device_ip}) berhasil di-onboard dengan status {$connStatus}.",
            'data' => $this->doorData($door->fresh()),
            'isapi_details' => $testResult['data'] ?? null,
        ], 201);
    }

    private function validateDoor(Request $request, ?Door $door = null): array
    {
        return $request->validate([
            'door_id' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('doors', 'door_id')->ignore($door?->id)],
            'name' => ['required', 'string', 'max:120'],
            'building_id' => ['required', 'integer', 'exists:buildings,id'],
            'zone_id' => ['nullable', 'integer', Rule::exists('zones', 'id')->where(fn ($query) => $query->where('building_id', $request->integer('building_id')))],
            'device_ip' => ['required', 'ip', Rule::unique('doors', 'device_ip')->ignore($door?->id)],
            'gateway' => ['nullable', 'ip'],
            'device_model' => ['required', 'string', 'max:120'],
        ]);
    }

    private function doorPayload(array $data, Building $building): array
    {
        return [
            'door_id' => strtoupper($data['door_id']), 'name' => $data['name'],
            'building_id' => $building->id, 'zone_id' => $data['zone_id'] ?? null,
            'location' => $building->name, 'device_ip' => $data['device_ip'],
            'gateway' => $data['gateway'] ?? null, 'device_model' => $data['device_model'],
        ];
    }

    private function doorData(Door $door): array
    {
        $door->load('building:id,name');
        return [
            'id' => $door->id, 'door_id' => $door->door_id, 'door_name' => $door->door_name,
            'building_id' => $door->building_id, 'building_name' => $door->building?->name,
            'location' => $door->location, 'device_ip' => $door->device_ip,
            'gateway' => $door->gateway, 'device_model' => $door->device_model,
            'connection_status' => $door->connection_status,
        ];
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $actor = $request->user();
        abort_unless($actor && $this->portalAccess->can($actor, $permission), 403);
    }

    private function audit(Request $request, string $action, string $subjectType, int $subjectId, string $description): void
    {
        ActivityLog::create(['admin_id' => $request->user()?->id, 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'description' => $description, 'timestamp' => now()]);
    }
}
