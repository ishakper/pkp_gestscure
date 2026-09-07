<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessLogResource;
use App\Models\AccessLog;
use Illuminate\Http\Request;

class AdminAccessLogController extends Controller
{
    public function index(Request $request)
    {
        $query = AccessLog::with(['door', 'employee']);

        $admin = $request->user();

        // RBAC filtering
        if ($admin && $admin->isBuildingAdmin() && $admin->assigned_building) {
            $query->whereHas('door', function ($q) use ($admin) {
                $q->where('location', $admin->assigned_building);
            });
        }

        // Filter by door_id
        if ($request->filled('door_id')) {
            $doorId = $request->door_id;
            $query->whereHas('door', function ($q) use ($doorId) {
                $q->where('door_id', $doorId)->orWhere('doors.id', $doorId);
            });
        }

        // Filter by status ('Granted' / 'Denied')
        if ($request->filled('status')) {
            $status = $request->status;
            $query->where(function ($q) use ($status) {
                $q->where('access_status', $status)
                  ->orWhere('status', $status);
            });
        } elseif ($request->filled('access_status')) {
            $status = $request->access_status;
            $query->where(function ($q) use ($status) {
                $q->where('access_status', $status)
                  ->orWhere('status', $status);
            });
        }

        // Filter by NIK or User
        if ($request->filled('user') || $request->filled('nik')) {
            $nik = $request->get('nik', $request->get('user'));
            $query->where(function ($q) use ($nik) {
                $q->where('nik', 'like', "%{$nik}%")
                  ->orWhereHas('employee', function ($sub) use ($nik) {
                      $sub->where('nik', 'like', "%{$nik}%")
                          ->orWhere('employee_id', 'like', "%{$nik}%")
                          ->orWhere('name', 'like', "%{$nik}%");
                  });
            });
        }

        // Filter by start_date & end_date
        if ($request->filled('start_date')) {
            $query->where('timestamp', '>=', $request->start_date . ' 00:00:00');
        }
        if ($request->filled('end_date')) {
            $query->where('timestamp', '<=', $request->end_date . ' 23:59:59');
        }

        // Ordering by newest
        $query->orderBy('timestamp', 'desc');

        $limit = (int) $request->get('limit', 0);
        $perPage = (int) $request->get('per_page', 15);

        if ($limit > 0) {
            $logs = $query->limit($limit)->get();
            return response()->json([
                'status' => 'success',
                'total_records' => $logs->count(),
                'data' => AccessLogResource::collection($logs),
            ]);
        }

        $paginatedLogs = $query->paginate($perPage);

        return response()->json([
            'status' => 'success',
            'pagination' => [
                'current_page' => $paginatedLogs->currentPage(),
                'per_page' => $paginatedLogs->perPage(),
                'total_records' => $paginatedLogs->total(),
                'total_pages' => $paginatedLogs->lastPage(),
            ],
            'data' => AccessLogResource::collection($paginatedLogs),
        ]);
    }
}
