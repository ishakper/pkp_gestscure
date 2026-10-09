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

        $configuredAllowedIps = array_filter(array_map(
            'trim',
            explode(',', (string) config('services.hikvision.allowed_device_ips', ''))
        ));

        $allowedIps = array_unique(array_merge($defaultAllowedIps, $dbDoorIps, $configuredAllowedIps));
        $clientIp = $request->ip();
        $isTrustedProxy = $clientIp === '127.0.0.1'
            || $clientIp === '::1'
            || in_array($clientIp, $configuredAllowedIps, true);

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
        $credentialProvided = $secretHeader !== null || $authHeader !== null;

        $claimedDoorId = $request->query('door_id', $request->input('door_id'));
        $claimedDoor = $claimedDoorId
            ? Door::where('door_id', $claimedDoorId)->first()
            : Door::where('device_ip', $clientIp)->first();
        if (!$claimedDoor || (!$isTrustedProxy && $claimedDoor->device_ip !== $clientIp)) {
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Akses ditolak: Identitas terminal tidak cocok dengan sumber webhook.',
            ], 403);
        }
        $validSecrets = array_filter([
            config("services.doors.{$claimedDoor->door_id}.webhook_secret"),
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
                if ($sanctumToken
                    && in_array('device:push-log', $sanctumToken->abilities ?? [], true)
                    && !in_array('*', $sanctumToken->abilities ?? [], true)
                    && $sanctumToken->name === "device-token-{$claimedDoor->door_id}") {
                    $isTokenValid = true;
                }
            }
        }

        $isPhysicalIpBound = !$credentialProvided
            && $request->isMethod('POST')
            && $request->is('api/v1/isapi/event-notification')
            && $claimedDoorId === $claimedDoor->door_id
            && !empty($claimedDoor->device_ip)
            && $request->server('REMOTE_ADDR') === $claimedDoor->device_ip
            && $clientIp === $claimedDoor->device_ip;

        if (!$isSecretValid && !$isTokenValid && !$isPhysicalIpBound) {
            \Illuminate\Support\Facades\Log::warning('[ISAPI Webhook] Unauthorized request or unrecognized device IP', [
                'ip' => $clientIp,
                'door_id' => $claimedDoor->door_id,
            ]);
            return response()->json([
                'status' => 'error',
                'code' => 403,
                'message' => 'Akses ditolak: Kredensial otentikasi terminal hardware (X-Device-Secret atau Bearer Token) tidak valid atau hilang.',
            ], 403);
        }

        if ($isPhysicalIpBound) {
            \Illuminate\Support\Facades\Log::info('[ISAPI Webhook] Physical device authenticated', [
                'auth_mode' => 'physical_ip_bound',
                'door_id' => $claimedDoor->door_id,
                'ip' => $clientIp,
            ]);
        }

        return $next($request);
    }
}
