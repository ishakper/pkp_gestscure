<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use App\Services\PortalAccess;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function __construct(private readonly PortalAccess $portalAccess) {}

    public function index(Request $request)
    {
        $actor = $request->user();
        abort_unless($actor && $actor->isSuperAdmin() && $this->portalAccess->can($actor, 'audit.view'), 403);
        $logs = ActivityLog::with('admin:id,name,email')
            ->orderBy('timestamp', 'desc')
            ->paginate(min(max((int) $request->get('per_page', 20), 1), 100));

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
