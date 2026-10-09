<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\{Building,Division,Position,Zone};
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class OrganizationController extends Controller
{
    #[OA\Get(
        path: '/user-management/organization/lookup',
        summary: 'Lookup Struktur Organisasi',
        description: 'Mendapatkan data opsi gedung, divisi, posisi, dan zona untuk dropdown form.',
        tags: ['Organization'],
        security: [['sanctum' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Berhasil mendapatkan data lookup',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'data' => [
                            'buildings' => [['id' => 1, 'code' => 'HQ', 'name' => 'Gedung Utama']],
                            'divisions' => [['id' => 1, 'building_id' => 1, 'code' => 'IT', 'name' => 'Informasi Teknologi']],
                            'positions' => [['id' => 1, 'division_id' => 1, 'code' => 'DEV', 'name' => 'Software Engineer']],
                            'zones' => [['id' => 1, 'building_id' => 1, 'code' => 'Z1', 'name' => 'Zona Terbatas Server']]
                        ]
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Unauthenticated')
        ]
    )]
    public function lookup(Request $request)
    {
        $admin = $request->user(); abort_unless($admin, 401);
        $buildings = Building::where('is_active', true);
        if ($admin->isBuildingAdmin() && $admin->assigned_building) $buildings->where('name', $admin->assigned_building);
        $buildingIds = (clone $buildings)->pluck('id');
        $divisions = Division::where('is_active', true)->when($admin->isBuildingAdmin(), fn($q) => $q->whereIn('building_id', $buildingIds));
        $divisionIds = (clone $divisions)->pluck('id');
        return response()->json(['status' => 'success', 'data' => ['buildings' => $buildings->orderBy('name')->get(['id', 'code', 'name']), 'divisions' => $divisions->orderBy('name')->get(['id', 'building_id', 'code', 'name']), 'positions' => Position::where('is_active', true)->when($admin->isBuildingAdmin(), fn($q) => $q->whereIn('division_id', $divisionIds))->orderBy('name')->get(['id', 'division_id', 'code', 'name']), 'zones' => Zone::where('is_active', true)->when($admin->isBuildingAdmin(), fn($q) => $q->whereIn('building_id', $buildingIds))->orderBy('name')->get(['id', 'building_id', 'code', 'name'])]]);
    }

    #[OA\Get(
        path: '/user-management/organization/{type}',
        summary: 'Daftar Entitas Organisasi',
        description: 'Mendapatkan daftar lengkap entitas gedung, divisi, posisi, atau zona (Khusus Super Admin).',
        tags: ['Organization'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', description: 'Tipe entitas (buildings | divisions | positions | zones)', required: true, schema: new OA\Schema(type: 'string', enum: ['buildings', 'divisions', 'positions', 'zones']))
        ],
        responses: [
            new OA\Response(response: 200, description: 'Data entitas berhasil diambil'),
            new OA\Response(response: 403, description: 'Akses ditolak (Bukan Super Admin)'),
            new OA\Response(response: 404, description: 'Tipe entitas tidak ditemukan')
        ]
    )]
    public function index(Request $request, string $type)
    {
        $this->ensureSuperAdmin($request);
        return response()->json(['status' => 'success', 'data' => $this->model($type)->orderBy('name')->get()]);
    }

    #[OA\Post(
        path: '/user-management/organization/{type}',
        summary: 'Tambah Entitas Organisasi',
        description: 'Membuat entitas baru untuk gedung, divisi, posisi, atau zona.',
        tags: ['Organization'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', description: 'Tipe entitas (buildings | divisions | positions | zones)', required: true, schema: new OA\Schema(type: 'string', enum: ['buildings', 'divisions', 'positions', 'zones']))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['code', 'name'],
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'DIV-IT'),
                    new OA\Property(property: 'name', type: 'string', example: 'Divisi TI'),
                    new OA\Property(property: 'building_id', type: 'integer', example: 1),
                    new OA\Property(property: 'division_id', type: 'integer', example: 1)
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Entitas berhasil dibuat'),
            new OA\Response(response: 403, description: 'Forbidden')
        ]
    )]
    public function store(Request $request, string $type)
    {
        $this->ensureSuperAdmin($request);
        $model = $this->model($type);
        $data = $request->validate($this->rules($type));
        return response()->json(['status' => 'success', 'data' => $model->create($data)], 201);
    }

    #[OA\Put(
        path: '/user-management/organization/{type}/{id}',
        summary: 'Perbarui Entitas Organisasi',
        description: 'Memperbarui data entitas organisasi.',
        tags: ['Organization'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'type', in: 'path', description: 'Tipe entitas (buildings | divisions | positions | zones)', required: true, schema: new OA\Schema(type: 'string', enum: ['buildings', 'divisions', 'positions', 'zones'])),
            new OA\Parameter(name: 'id', in: 'path', description: 'ID entitas', required: true, schema: new OA\Schema(type: 'integer'))
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'code', type: 'string', example: 'DIV-IT-REV'),
                    new OA\Property(property: 'name', type: 'string', example: 'Divisi TI & Infrastruktur')
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Entitas berhasil diperbarui')
        ]
    )]
    public function update(Request $request, string $type, int $id)
    {
        $this->ensureSuperAdmin($request);
        $item = $this->model($type)->findOrFail($id);
        $item->update($request->validate($this->rules($type, $id)));
        return response()->json(['status' => 'success', 'data' => $item]);
    }

    private function ensureSuperAdmin(Request $request): void { abort_unless($request->user()?->isSuperAdmin(), 403); }
    private function model(string $type) { return match($type) {'buildings' => new Building, 'divisions' => new Division, 'positions' => new Position, 'zones' => new Zone, default => abort(404)}; }
    private function rules(string $type, ?int $id = null): array { $unique = fn($column) => 'required|string|max:100|unique:' . $type . ',' . $column . ($id ? ',' . $id : ''); $base = ['code' => $unique('code'), 'name' => 'required|string|max:255', 'is_active' => 'sometimes|boolean']; return match($type) {'buildings' => $base + ['description' => 'nullable|string|max:1000'], 'divisions' => $base + ['building_id' => 'nullable|exists:buildings,id'], 'positions' => $base + ['division_id' => 'nullable|exists:divisions,id'], 'zones' => $base + ['building_id' => 'required|exists:buildings,id']}; }
}
