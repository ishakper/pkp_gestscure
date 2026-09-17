<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\PortalAccess;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ActivityLogController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    #[OA\Get(
        path: '/admin/activity-logs',
        summary: 'Audit Log Aktivitas Sistem',
        description: 'Mendapatkan log jejak audit aktivitas admin/pengguna dalam sistem PKP SecureGate.',
        tags: ['Audit Log'],
        security: [['sanctum' => []]],
        parameters: [
            new OA\Parameter(name: 'page', in: 'query', description: 'Halaman paginasi', required: false, schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Jumlah data per halaman', required: false, schema: new OA\Schema(type: 'integer', default: 20))
        ],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Daftar log aktivitas berhasil diambil',
                content: new OA\JsonContent(
                    example: [
                        'status' => 'success',
                        'pagination' => ['current_page' => 1, 'per_page' => 20, 'total_records' => 1, 'total_pages' => 1],
                        'data' => [
                            [
                                'id' => 1,
                                'admin_name' => 'Super Admin',
                                'action' => 'admin_login',
                                'description' => 'Admin Super Admin login to dashboard',
                                'timestamp' => '2026-09-17 10:00:00'
                            ]
                        ]
                    ]
                )
            ),
            new OA\Response(response: 403, description: 'Forbidden')
        ]
    )]
    public function index(Request $request)
    {
        abort_unless($this->portalAccess->can($request->user(), 'audit.view'), 403);
        $logs = ActivityLog::with('admin')
            ->orderBy('timestamp', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'status' => 'success',
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total_records' => $logs->total(),
                'total_pages' => $logs->lastPage(),
            ],
            'data' => ActivityLogResource::collection($logs),
        ]);
    }
}
