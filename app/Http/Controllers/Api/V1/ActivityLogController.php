<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
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
