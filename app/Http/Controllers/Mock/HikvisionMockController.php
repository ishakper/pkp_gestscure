<?php

namespace App\Http\Controllers\Mock;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HikvisionMockController extends Controller
{
    /**
     * Simulate ISAPI GET /ISAPI/System/deviceInfo and /ISAPI/System/status
     * Returns terminal device status and hardware info.
     */
    public function deviceStatus(): JsonResponse
    {
        $currentTime = now()->toIso8601String();

        return response()->json([
            'statusCode' => 1,
            'statusString' => 'OK',
            'model' => 'DS-K1T804AMF',
            'serialNumber' => 'DS-K1T804AMF20260901',
            'firmware' => 'V1.2.3 build 260901',
            'doorStatus' => 'closed',
            'DeviceStatus' => [
                'status' => 'OK',
                'online' => true,
                'currentDeviceTime' => $currentTime,
                'doorStatus' => 'closed',
            ],
            'DeviceInfo' => [
                'deviceName' => 'Access Controller',
                'model' => 'DS-K1T804AMF',
                'serialNumber' => 'DS-K1T804AMF20260901',
                'firmwareVersion' => 'V1.2.3 build 260901',
                'doorStatus' => 'closed',
            ],
            'status' => 'OK',
            'online' => true,
            'currentDeviceTime' => $currentTime,
        ], 200);
    }

    /**
     * Simulate ISAPI PUT /ISAPI/AccessControl/RemoteControl/door/{doorNo}
     */
    public function remoteControl(Request $request, $doorNo = 1): JsonResponse
    {
        return response()->json([
            'statusCode' => 1,
            'statusString' => 'OK',
            'subStatusCode' => 'ok',
            'errorCode' => 0,
            'errorMsg' => 'OK',
        ], 200);
    }

    /**
     * Simulate ISAPI PUT /ISAPI/AccessControl/CardInfo/Record
     * Simulates sending/syncing card & user information to the device.
     */
    public function syncCard(Request $request): JsonResponse
    {
        $cardNo = $request->input('CardInfo.cardNo')
            ?? $request->input('cardNo')
            ?? $request->input('card_no')
            ?? $request->input('CardInfo.cardNumber')
            ?? $request->input('cardNumber');

        $employeeNo = $request->input('CardInfo.employeeNo')
            ?? $request->input('employeeNo')
            ?? $request->input('employee_no')
            ?? $request->input('CardInfo.employee_id')
            ?? $request->input('employee_id');

        // Validation: cardNo and employeeNo must not be empty
        if (empty($cardNo) || empty($employeeNo)) {
            return response()->json([
                'statusCode' => 4,
                'statusString' => 'Invalid Operation',
                'subStatusCode' => 'badParameters',
                'errorMsg' => 'cardNo and employeeNo are required.',
                'ResponseStatus' => [
                    'requestURL' => '/ISAPI/AccessControl/CardInfo/Record',
                    'statusCode' => 4,
                    'statusString' => 'Invalid Operation',
                    'subStatusCode' => 'badParameters',
                    'errorMsg' => 'cardNo and employeeNo are required.',
                ],
            ], 400);
        }

        return response()->json([
            'statusCode' => 1,
            'statusString' => 'OK',
            'subStatusCode' => 'ok',
            'ResponseStatus' => [
                'requestURL' => '/ISAPI/AccessControl/CardInfo/Record',
                'statusCode' => 1,
                'statusString' => 'OK',
                'subStatusCode' => 'ok',
            ],
            'cardNo' => (string) $cardNo,
            'employeeNo' => (string) $employeeNo,
        ], 200);
    }

    /**
     * Simulate ISAPI POST /ISAPI/AccessControl/AcsEvent
     * Simulates retrieving access tap logs (AcsEvent) with card and fingerprint history.
     */
    public function fetchAccessLogs(Request $request): JsonResponse
    {
        $events = [
            [
                'major' => 5,
                'minor' => 1,
                'time' => now()->subMinutes(30)->toIso8601String(),
                'cardNo' => 'CARD-100234',
                'cardType' => 1,
                'employeeNoString' => 'EMP-001',
                'name' => 'Budi Santoso',
                'currentVerifyMode' => 'card',
                'verifyMethod' => 'Card',
                'doorNo' => 1,
                'doorName' => 'Pintu Utama (DOOR-A)',
                'accessStatus' => 'Granted',
                'mask' => 'no',
            ],
            [
                'major' => 5,
                'minor' => 2,
                'time' => now()->subMinutes(15)->toIso8601String(),
                'cardNo' => '',
                'cardType' => 0,
                'employeeNoString' => 'EMP-002',
                'name' => 'Siti Rahma',
                'currentVerifyMode' => 'fingerprint',
                'verifyMethod' => 'Fingerprint',
                'doorNo' => 1,
                'doorName' => 'Pintu Utama (DOOR-A)',
                'accessStatus' => 'Granted',
                'mask' => 'no',
            ],
            [
                'major' => 5,
                'minor' => 1,
                'time' => now()->subMinutes(5)->toIso8601String(),
                'cardNo' => 'CARD-999888',
                'cardType' => 1,
                'employeeNoString' => 'UNKNOWN',
                'name' => 'Unknown',
                'currentVerifyMode' => 'card',
                'verifyMethod' => 'Card',
                'doorNo' => 2,
                'doorName' => 'Ruang Server (DOOR-B)',
                'accessStatus' => 'Denied',
                'mask' => 'no',
            ],
        ];

        return response()->json([
            'statusCode' => 1,
            'statusString' => 'OK',
            'AcsEvent' => [
                'searchID' => '1',
                'responseStatusStrg' => 'OK',
                'numOfMatches' => count($events),
                'totalMatches' => count($events),
                'InfoList' => $events,
            ],
            'events' => $events,
        ], 200);
    }
}
