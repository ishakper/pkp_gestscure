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

        if (!in_array($clientIp, $allowedIps, true)) {
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
            env('DOOR_A_WEBHOOK_SECRET', 'secret_door_a_9981'),
            env('DOOR_B_WEBHOOK_SECRET', 'secret_door_b_9982'),
            env('DOOR_C_WEBHOOK_SECRET', 'secret_door_c_9983'),
            env('DOOR_D_WEBHOOK_SECRET', 'secret_door_d_9984'),
            env('ISAPI_DEVICE_SECRET', 'secret_simulator_key_2026'),
            'secret_simulator_key_2026',
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

        if (!$isSecretValid && !$isTokenValid) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Akses ditolak: Kredensial otentikasi terminal hardware (X-Device-Secret atau Bearer Token) tidak valid atau hilang.',
            ], 403);
        }

        return $next($request);
    }
}
