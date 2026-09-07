<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class IsapiWebhookController extends Controller
{
    public function handleEventNotification(Request $request)
    {
        // 1. Strict Request Validation & Anti-XSS regex to block HTML injection (< or >)
        $validated = $request->validate([
            'door_id' => 'nullable|string|max:50|regex:/^[^<>]*$/',
            'device_ip' => 'nullable|ip',
            'card_number' => 'nullable|string|max:50|regex:/^[^<>]*$/',
            'card_no' => 'nullable|string|max:50|regex:/^[^<>]*$/',
            'card' => 'nullable|string|max:50|regex:/^[^<>]*$/',
            'user' => 'nullable|string|max:100|regex:/^[^<>]*$/',
            'nik' => 'nullable|string|max:50|regex:/^[^<>]*$/',
            'employee_id' => 'nullable|string|max:50|regex:/^[^<>]*$/',
            'verify_method' => 'nullable|string|in:Fingerprint,Card,Face,PIN,fingerprint,card,face,pin',
            'access_status' => 'nullable|string|in:Granted,Denied,granted,denied',
            'timestamp' => 'nullable|date',
            'reason' => 'nullable|string|max:255|regex:/^[^<>]*$/',
        ], [
            'door_id.regex' => 'Parameter door_id mengandung karakter terlarang (< atau >).',
            'card_number.regex' => 'Parameter card_number mengandung karakter terlarang (< atau >).',
            'card_no.regex' => 'Parameter card_no mengandung karakter terlarang (< atau >).',
            'card.regex' => 'Parameter card mengandung karakter terlarang (< atau >).',
            'user.regex' => 'Parameter user mengandung karakter terlarang (< atau >).',
            'nik.regex' => 'Parameter nik mengandung karakter terlarang (< atau >).',
            'employee_id.regex' => 'Parameter employee_id mengandung karakter terlarang (< atau >).',
            'reason.regex' => 'Parameter reason mengandung karakter terlarang (< atau >).',
        ]);

        $doorId = $validated['door_id'] ?? null;
        $deviceIp = $validated['device_ip'] ?? $request->ip();
        $cardNo = $validated['card_number'] ?? ($validated['card_no'] ?? ($validated['card'] ?? null));
        $nikOrEmployeeId = $validated['user'] ?? ($validated['nik'] ?? ($validated['employee_id'] ?? $cardNo));
        $rawVerify = $validated['verify_method'] ?? ($cardNo ? 'Card' : 'Fingerprint');
        $verifyMethod = in_array(ucfirst(strtolower($rawVerify)), ['Card', 'Fingerprint'])
            ? ucfirst(strtolower($rawVerify))
            : ($cardNo ? 'Card' : 'Fingerprint');
        $eventTimestamp = $validated['timestamp'] ?? now()->toIso8601String();

        // 2. Resolve Door Device (Strict: No fallback to Door::first())
        $door = null;
        if ($doorId) {
            $door = Door::where('door_id', $doorId)->first();
        }
        if (!$door && $deviceIp) {
            $door = Door::where('device_ip', $deviceIp)->first();
        }

        // Return HTTP 404 if device is not registered in system
        if (!$door) {
            return response()->json([
                'status' => 'error',
                'code' => 404,
                'message' => 'Perangkat terminal pintu tidak terdaftar (Unregistered Device). Hubungi administrator sistem.',
            ], 404);
        }

        // 3. Resolve Employee by NIK, employee_id, or card_no
        $employee = null;
        if ($nikOrEmployeeId) {
            $employee = Employee::where('nik', $nikOrEmployeeId)
                ->orWhere('employee_id', $nikOrEmployeeId)
                ->orWhere('card_no', $nikOrEmployeeId)
                ->first();
        }
        if (!$employee && $cardNo) {
            $employee = Employee::where('card_no', $cardNo)->first();
        }

        // Determine access status
        if (isset($validated['access_status'])) {
            $accessStatus = ucfirst(strtolower($validated['access_status']));
        } else {
            $accessStatus = $employee ? 'Granted' : 'Denied';
        }

        // 4. Generate Unique Log ID
        $logId = 'LOG-' . date('YmdHis') . '-' . Str::random(4);

        // 5. Create Access Log Entry with sanitized fields
        $accessLog = AccessLog::create([
            'log_id' => strtoupper($logId),
            'door_id' => $door->id,
            'employee_id' => $employee ? $employee->id : null,
            'nik' => $employee ? $employee->nik : ($nikOrEmployeeId ? substr(strip_tags($nikOrEmployeeId), 0, 50) : null),
            'device_ip' => $deviceIp,
            'verify_method' => $verifyMethod,
            'access_status' => in_array($accessStatus, ['Granted', 'Denied']) ? $accessStatus : 'Granted',
            'reason' => $validated['reason'] ?? ($accessStatus === 'Denied' && !$employee ? 'Unknown Card / Unregistered User' : null),
            'timestamp' => $eventTimestamp ? date('Y-m-d H:i:s', strtotime($eventTimestamp)) : now(),
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Event notifikasi tap akses berhasil dicatat',
            'data' => [
                'log_id' => $accessLog->log_id,
                'door_id' => $door->door_id,
                'door_name' => $door->door_name,
                'employee_name' => $employee ? $employee->name : 'Unknown / Unregistered Card',
                'access_status' => $accessLog->access_status,
                'timestamp' => $accessLog->timestamp instanceof \DateTimeInterface ? $accessLog->timestamp->toIso8601String() : \Carbon\Carbon::parse($accessLog->timestamp)->toIso8601String(),
            ],
        ], 200);
    }
}
