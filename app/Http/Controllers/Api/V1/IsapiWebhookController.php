<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Door;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class IsapiWebhookController extends Controller
{
    public function handleEventNotification(Request $request)
    {
        // 0. Detect and Parse Raw Payload using the centralized parser
        $rawContent = (string) $request->getContent();
        $contentType = (string) $request->header('Content-Type', '');
        
        $parsedEvent = $request->attributes->get('hikvision_parsed_event') 
            ?: \App\Services\HikvisionPayloadParser::parse($rawContent, $contentType);

        if (app()->environment('testing')) {
            \Illuminate\Support\Facades\Log::info("DEBUG PARSER:", ['raw' => $rawContent, 'parsed' => $parsedEvent]);
        }

        if ($parsedEvent && $parsedEvent['is_valid_event']) {
            $major = $parsedEvent['major_event'] ?? null;
            $sub = $parsedEvent['minor_event'] ?? null;
            
            // Map event type
            $eventType = 'STANDARD_TAP';
            if ($major === 5) {
                if (in_array((int)$sub, [37, 38])) {
                    $eventType = 'TAMPER_ALARM';
                } else {
                    $eventType = 'DOOR_FORCED_OPEN';
                }
            }

            $cardNo = $parsedEvent['card_reference'] ?? '';
            $employeeId = $parsedEvent['employee_no'] ?? '';
            
            $verifyMethod = !empty($cardNo) ? 'Card' : 'Fingerprint';
            
            $timestamp = $parsedEvent['event_time'] ?: now()->toIso8601String();
            
            $deviceIp = $parsedEvent['device_ip'] ?: $request->ip();

            $mergedData = [
                'device_ip' => $deviceIp,
                'card_number' => !empty($cardNo) ? $cardNo : null,
                'card_no' => !empty($cardNo) ? $cardNo : null,
                'employee_id' => !empty($employeeId) ? $employeeId : null,
                'user' => !empty($employeeId) ? $employeeId : (!empty($cardNo) ? $cardNo : null),
                'event_type' => $eventType,
                'verify_method' => $verifyMethod,
                'timestamp' => $timestamp,
            ];

            // 1. door_id query parameter
            $queryDoorId = $request->query('door_id');
            if ($queryDoorId) {
                $door = \App\Models\Door::where('door_id', $queryDoorId)->first();
                if ($door) {
                    $mergedData['door_id'] = $door->door_id;
                    $mergedData['device_ip'] = $door->device_ip ?: $deviceIp;
                    $deviceIp = $mergedData['device_ip'];
                }
            }

            // Extract serialNo for deduplication
            $mergedData['serial_no'] = $parsedEvent['serial_no'] ?? null;

            // Merge sanitized parameters into request so they pass anti-XSS regex validation
            $request->merge(array_filter($mergedData, fn($v) => !is_null($v)));
        }

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
            'event_type' => 'nullable|string|max:50|regex:/^[^<>]*$/',
            'verify_method' => 'nullable|string|max:50|regex:/^[^<>]*$/',
            'access_status' => 'nullable|string|max:50|regex:/^[^<>]*$/',
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
            'event_type.regex' => 'Parameter event_type mengandung karakter terlarang (< atau >).',
            'reason.regex' => 'Parameter reason mengandung karakter terlarang (< atau >).',
        ]);

        $doorId = $validated['door_id'] ?? $request->query('door_id');
        $deviceIp = $validated['device_ip'] ?? $request->ip();
        $cardNo = $validated['card_number'] ?? ($validated['card_no'] ?? ($validated['card'] ?? null));
        $nikOrEmployeeId = $validated['user'] ?? ($validated['nik'] ?? ($validated['employee_id'] ?? $cardNo));
        $eventType = strtoupper($validated['event_type'] ?? 'STANDARD_TAP');
        $eventTimestamp = $validated['timestamp'] ?? now()->toIso8601String();
        $serialNo = $request->input('serial_no');

        if (app()->environment('testing')) {
            \Illuminate\Support\Facades\Log::info("DEBUG TEST:", ['cardNo' => $cardNo, 'nik' => $nikOrEmployeeId, 'payload' => $request->all(), 'doorId' => $doorId, 'deviceIp' => $deviceIp]);
        }

        // 2. Resolve Door Device (Strict Deterministic Order)
        $door = null;
        if ($doorId) {
            $door = Door::where('door_id', $doorId)->first();
        }
        
        if (!$door && !empty($parsedEvent['device_ip'])) {
            $door = Door::where('device_ip', $parsedEvent['device_ip'])->first();
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

        // 3. Process Specific Event Types (Alarms, Duress, or Standard Tap)
        $employee = null;
        $reason = $validated['reason'] ?? null;

        if ($eventType === 'DOOR_FORCED_OPEN') {
            $verifyMethod = $validated['verify_method'] ?? 'Sensor';
            $accessStatus = 'Alarm';
            $displayNik = $nikOrEmployeeId ? substr(strip_tags($nikOrEmployeeId), 0, 50) : 'SENSOR-FORCED-OPEN';
            $reason = $reason ?: '[CRITICAL ALARM] Pintu Dibuka Paksa (Door Forced Open) - Potensi Pembobolan / Intrusi Ilegal!';
        } elseif ($eventType === 'TAMPER_ALARM') {
            $verifyMethod = $validated['verify_method'] ?? 'Sensor';
            $accessStatus = 'Alarm';
            $displayNik = $nikOrEmployeeId ? substr(strip_tags($nikOrEmployeeId), 0, 50) : 'SENSOR-TAMPER';
            $reason = $reason ?: '[CRITICAL ALARM] Sensor Sabotase Aktif (Tamper Alarm) - Perangkat Terminal Dilepas / Dibongkar!';
        } elseif ($eventType === 'DURESS_FINGERPRINT') {
            if ($nikOrEmployeeId) {
                $employee = Employee::where('nik', $nikOrEmployeeId)
                    ->orWhere('employee_id', $nikOrEmployeeId)
                    ->orWhere('card_no', $nikOrEmployeeId)
                    ->first();
            }
            $verifyMethod = $validated['verify_method'] ?? 'Duress_Fingerprint';
            $accessStatus = 'Duress';
            $displayNik = $employee ? $employee->nik : ($nikOrEmployeeId ? substr(strip_tags($nikOrEmployeeId), 0, 50) : 'DURESS-USER');
            $reason = $reason ?: '[EMERGENCY DURESS] Akses Pintu Dibuka di Bawah Ancaman (Duress Alarm Triggered)!';
        } else {
            // Standard Tap Scenario
            $eventType = 'STANDARD_TAP';
            if ($nikOrEmployeeId) {
                $employee = Employee::where('nik', $nikOrEmployeeId)
                    ->orWhere('employee_id', $nikOrEmployeeId)
                    ->orWhere('card_no', $nikOrEmployeeId)
                    ->first();
            }
            if (!$employee && $cardNo) {
                $employee = Employee::where('card_no', $cardNo)->first();
            }

            $rawVerify = $validated['verify_method'] ?? ($cardNo ? 'Card' : 'Fingerprint');
            $verifyMethod = in_array(ucfirst(strtolower($rawVerify)), ['Card', 'Fingerprint', 'Face', 'Pin'])
                ? ucfirst(strtolower($rawVerify))
                : ($cardNo ? 'Card' : 'Fingerprint');

            if (isset($validated['access_status'])) {
                $accessStatus = ucfirst(strtolower($validated['access_status']));
            } else {
                $accessStatus = $employee ? 'Granted' : 'Denied';
            }

            $displayNik = $employee ? $employee->nik : ($nikOrEmployeeId ? substr(strip_tags($nikOrEmployeeId), 0, 50) : null);
            $reason = $reason ?: ($accessStatus === 'Denied' && !$employee ? 'Unknown Card / Unregistered User' : null);
        }

        // 4. Deduplication
        if ($serialNo) {
            $existingLog = AccessLog::where('door_id', $door->id)
                ->where('device_serial', $serialNo)
                ->where('event_type', $eventType)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->first();
                
            if ($existingLog) {
                return response()->json([
                    'status' => 'success',
                    'message' => 'Event duplikat diabaikan',
                    'data' => [
                        'log_id' => $existingLog->log_id,
                        'door_id' => $door->door_id,
                    ],
                ], 200);
            }
        }

        // 5. Generate Unique Log ID
        $logId = 'LOG-' . date('YmdHis') . '-' . Str::random(4);

        // 6. Create Access Log Entry with sanitized fields
        $accessLog = AccessLog::create([
            'log_id' => strtoupper($logId),
            'door_id' => $door->id,
            'employee_id' => $employee ? $employee->id : null,
            'nik' => $displayNik,
            'event_type' => $eventType,
            'device_ip' => $deviceIp,
            'verify_method' => $verifyMethod,
            'access_status' => $accessStatus,
            'reason' => $reason,
            'timestamp' => $eventTimestamp ? date('Y-m-d H:i:s', strtotime($eventTimestamp)) : now(),
            'device_serial' => $serialNo,
        ]);

        Log::info('[ISAPI Webhook] Access event recorded', [
            'log_id' => $accessLog->log_id,
            'door_id' => $door->door_id,
            'device_ip' => $deviceIp,
            'event_type' => $eventType,
            'access_status' => $accessLog->access_status,
            'verify_method' => $verifyMethod,
            'employee_id' => $employee?->employee_id,
            'device_serial' => $serialNo,
            'source_format' => $parsedEvent['source_format'] ?? 'REQUEST',
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Event notifikasi tap / alarm akses berhasil dicatat',
            'data' => [
                'log_id' => $accessLog->log_id,
                'event_type' => $eventType,
                'door_id' => $door->door_id,
                'door_name' => $door->door_name,
                'employee_name' => $employee ? $employee->name : ($eventType === 'STANDARD_TAP' ? 'Unknown / Unregistered Card' : 'Security Alarm Event'),
                'access_status' => $accessLog->access_status,
                'reason' => $accessLog->reason,
                'timestamp' => $accessLog->timestamp instanceof \DateTimeInterface ? $accessLog->timestamp->toIso8601String() : \Carbon\Carbon::parse($accessLog->timestamp)->toIso8601String(),
            ],
        ], 200);
    }
}
