<?php

namespace App\Http\Middleware;

use App\Models\Door;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class VerifyDeviceWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Dynamic IP Whitelisting (Database + Static Config + Loopback)
        $defaultAllowedIps = [
            '192.168.90.11',
            '192.168.90.12',
            '192.168.90.15',
            '192.168.90.13',
            '192.168.90.14',
            '192.168.90.81',
            '192.168.90.100',
            '127.0.0.1',
            '::1',
        ];

        // Fetch dynamic IPs from registered doors table
        try {
            $dbDoorIps = Door::whereNotNull('device_ip')->pluck('device_ip')->filter()->toArray();
        } catch (\Throwable $e) {
            $dbDoorIps = [];
        }

        // Environment-configured IPs
        $envIps = env('ALLOWED_DEVICE_IPS') ? array_map('trim', explode(',', env('ALLOWED_DEVICE_IPS'))) : [];

        $allowedIps = array_unique(array_merge($defaultAllowedIps, $dbDoorIps, $envIps));
        $clientIp = $request->ip();
        $isTrustedProxy = str_starts_with($clientIp, '172.') || str_starts_with($clientIp, '10.') || $clientIp === '127.0.0.1';

        if (!$isTrustedProxy && !in_array($clientIp, $allowedIps, true)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => "Akses ditolak: IP perangkat ({$clientIp}) tidak terdaftar dalam whitelist terminal pintu fisik.",
            ], 403);
        }

        // 2. Strict Authorization Header & Device Secret Validation
        $secretHeader = $request->header('X-Device-Secret');
        $authHeader = $request->header('Authorization');

        $validSecrets = array_filter([
            config('services.doors.DOOR-A.webhook_secret'),
            config('services.doors.DOOR-B.webhook_secret'),
            config('services.doors.DOOR-C.webhook_secret'),
            config('services.doors.DOOR-D.webhook_secret'),
            config('services.hikvision.device_secret'),
        ]);

        $isSecretValid = ($secretHeader && in_array($secretHeader, $validSecrets, true));

        // Support Bearer Token with 'device:push-log' Sanctum ability or matching secret
        $isTokenValid = false;
        if ($authHeader && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            $bearerToken = $matches[1];
            if (in_array($bearerToken, $validSecrets, true)) {
                $isTokenValid = true;
            } else {
                $sanctumToken = PersonalAccessToken::findToken($bearerToken);
                if ($sanctumToken && ($sanctumToken->can('device:push-log') || $sanctumToken->can('*'))) {
                    $isTokenValid = true;
                }
            }
        }

        // If no secret provided, but the IP is confirmed as a physical registered door, allow it as a physical event fallback
        $hasSecretProvided = ($isSecretValid || $isTokenValid);
        $isPhysicalHardwareEvent = false;

        if (!$hasSecretProvided) {
            $rawContent = (string) $request->getContent();
            $contentType = (string) $request->header('Content-Type', '');
            
            $parsedEvent = \App\Services\HikvisionPayloadParser::parse($rawContent, $contentType);

            $isRegisteredDoorIp = false;
            
            // Check door_id query parameter first
            $queryDoorId = $request->query('door_id');
            if ($queryDoorId && $isTrustedProxy) {
                // For proxy clients, if door_id is present, ensure it maps to a real door
                $queryDoor = Door::where('door_id', $queryDoorId)->first();
                if ($queryDoor && !empty($queryDoor->device_ip) && in_array($queryDoor->device_ip, $allowedIps, true)) {
                    $isRegisteredDoorIp = true;
                }
            } else {
                $isRegisteredDoorIp = in_array($clientIp, $allowedIps, true);
            }

            if ($parsedEvent && $parsedEvent['is_valid_event']) {
                if (!$isRegisteredDoorIp) {
                    $payloadIp = $parsedEvent['device_ip'];
                    
                    if ($isTrustedProxy && !empty($payloadIp) && in_array($payloadIp, $allowedIps, true)) {
                        $isRegisteredDoorIp = true;
                    }
                }

                if ($isRegisteredDoorIp) {
                    $isPhysicalHardwareEvent = true;
                    $request->attributes->set('hikvision_parsed_event', $parsedEvent);
                }
            }
        }

        if (!$hasSecretProvided && !$isPhysicalHardwareEvent) {
            \Illuminate\Support\Facades\Log::warning('[ISAPI Webhook] Unauthorized request or unrecognized device IP', [
                'ip' => $clientIp,
            ]);
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Akses ditolak: Kredensial otentikasi terminal hardware (X-Device-Secret atau Bearer Token) tidak valid atau hilang.',
            ], 403);
        }

        return $next($request);
    }
}
