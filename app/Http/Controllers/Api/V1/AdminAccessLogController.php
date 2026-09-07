<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AccessLogResource;
use App\Models\AccessLog;
use App\Models\ActivityLog;
use App\Models\Door;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    /**
     * Pull / Synchronize access tap logs from ISAPI AcsEvent hardware / mock endpoint
     */
    public function syncHardware(Request $request, HikvisionIsapiService $isapiService)
    {
        $doorId = $request->input('door_id');
        $limit = (int) $request->input('limit', 30);

        $doors = $doorId 
            ? Door::where('door_id', $doorId)->orWhere('id', $doorId)->get()
            : Door::all();

        if ($doors->isEmpty()) {
            $doors = collect([null]);
        }

        $totalInserted = 0;
        $totalFetched = 0;

        foreach ($doors as $door) {
            $res = $isapiService->fetchEvents($limit, $door);

            if (!empty($res['status']) && !empty($res['events'])) {
                $totalFetched += count($res['events']);

                foreach ($res['events'] as $evt) {
                    $eventTime = !empty($evt['time']) ? date('Y-m-d H:i:s', strtotime($evt['time'])) : now()->toDateTimeString();

                    // Find Door target
                    $targetDoor = $door;
                    if (!$targetDoor && !empty($evt['door_name'])) {
                        $targetDoor = Door::where('door_name', 'like', "%{$evt['door_name']}%")
                            ->orWhere('door_id', 'like', "%{$evt['door_name']}%")
                            ->first();
                    }
                    if (!$targetDoor) {
                        $targetDoor = Door::first();
                    }

                    if (!$targetDoor) continue;

                    // Resolve Employee
                    $emp = null;
                    if (!empty($evt['employee_no'])) {
                        $emp = Employee::where('employee_id', $evt['employee_no'])
                            ->orWhere('nik', $evt['employee_no'])
                            ->first();
                    }
                    if (!$emp && !empty($evt['card_no'])) {
                        $emp = Employee::where('card_no', $evt['card_no'])->first();
                    }
                    if (!$emp && !empty($evt['name'])) {
                        $emp = Employee::where('name', $evt['name'])->first();
                    }

                    // Check for existing log with same timestamp & door
                    $existing = AccessLog::where('door_id', $targetDoor->id)
                        ->where('timestamp', $eventTime)
                        ->first();

                    if (!$existing) {
                        $logId = 'LOG-' . date('YmdHis', strtotime($eventTime)) . '-' . strtoupper(Str::random(4));
                        
                        AccessLog::create([
                            'log_id' => $logId,
                            'door_id' => $targetDoor->id,
                            'employee_id' => $emp ? $emp->id : null,
                            'nik' => $emp ? $emp->nik : ($evt['employee_no'] ?? null),
                            'device_ip' => $targetDoor->device_ip,
                            'verify_method' => in_array($evt['verify_method'] ?? '', ['Card', 'Fingerprint', 'Face', 'PIN']) ? $evt['verify_method'] : 'Card',
                            'access_status' => in_array($evt['access_status'] ?? '', ['Granted', 'Denied']) ? $evt['access_status'] : 'Granted',
                            'reason' => ($evt['access_status'] === 'Denied' && !$emp) ? 'Unknown Card / Intrusion' : null,
                            'timestamp' => $eventTime,
                        ]);

                        $totalInserted++;
                    }
                }
            }
        }

        ActivityLog::create([
            'admin_id' => $request->user()->id ?? null,
            'action' => 'sync_hardware_access_logs',
            'description' => "Pulled ISAPI events: {$totalFetched} fetched, {$totalInserted} new records inserted into database.",
            'timestamp' => now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => "Sinkronisasi log ISAPI selesai: {$totalInserted} event baru berhasil disimpan dari {$totalFetched} data riwayat.",
            'inserted_count' => $totalInserted,
            'total_fetched' => $totalFetched,
        ]);
    }
}
