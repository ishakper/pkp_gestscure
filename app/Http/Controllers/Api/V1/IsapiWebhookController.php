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
    /**
     * Browser simulation is authenticated as an administrator and never uses
     * (or exposes) a physical terminal's webhook secret.
     */
    public function simulateEvent(Request $request)
    {
        if (!$request->user()?->isSuperAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Unauthorized.'], 403);
        }

        return $this->handleEventNotification($request);
    }

    public function handleEventNotification(Request $request)
    {
        // 0. Detect and Parse Raw Payload using the centralized parser
        $rawContent = (string) $request->getContent();
        $contentType = (string) $request->header('Content-Type', '');
        
        $parsedEvent = $request->attributes->get('hikvision_parsed_event') 
            ?: \App\Services\HikvisionPayloadParser::parse($rawContent, $contentType);


        if ($parsedEvent && $parsedEvent['is_valid_event']) {
            $major = $parsedEvent['major_event'] ?? null;
            $sub = $parsedEvent['minor_event'] ?? null;
            
            // Map event type
            $eventType = 'STANDARD_TAP';
            if ($major === 5) {
                if (in_array((int)$sub, [37, 38])) {
                    $eventType = 'TAMPER_ALARM';
                } elseif (in_array((int)$sub, [21, 22])) {
                    $eventType = 'DOOR_FORCED_OPEN';
                } elseif (in_array((int)$sub, [26, 27])) {
                    $eventType = 'DURESS_FINGERPRINT';
                }
            }

            $cardNo = $parsedEvent['card_reference'] ?? '';
            $employeeId = $parsedEvent['employee_no'] ?? '';
            $verifyMethod = $parsedEvent['verification_method'] !== 'UNKNOWN'
                ? $parsedEvent['verification_method']
                : (!empty($cardNo) ? 'Card' : 'UNKNOWN');

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
                'direction' => $parsedEvent['direction'] ?? 'UNKNOWN',
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
            $mergedData['serial_no'] = $parsedEvent['device_serial'] ?? null;

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
            'direction' => 'nullable|in:ENTRY,EXIT,UNKNOWN',
            'serial_no' => 'nullable|string|max:100|regex:/^[^<>]*$/',
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

        $source = $request->routeIs('isapi.event-notification') ? 'HIKVISION_WEBHOOK' : 'SIMULATOR';

        $eventPayload = array_merge($validated, [
            'door_id' => $doorId,
            'device_ip' => $deviceIp,
            'source_format' => $parsedEvent['source_format'] ?? 'REQUEST',
        ]);

        $ingestionService = app(\App\Services\HikvisionEventIngestionService::class);
        $result = $ingestionService->ingest($eventPayload, null, $source);

        $httpCode = $result['code'] ?? 200;
        unset($result['code'], $result['access_log']);

        return response()->json($result, $httpCode);
    }
}
