<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class IpCidrValidator
{
    /**
     * Validate IP address against CIDR allowlist.
     * Rejects: loopback, link-local, multicast, broadcast, metadata, unspecified, dan IP di luar allowlist.
     * Production fail-closed jika allowlist kosong.
     */
    public static function validateManualDeviceIp(string $ip): array
    {
        // Input validation
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return ['allowed' => false, 'reason' => 'Invalid IP address format'];
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return ['allowed' => false, 'reason' => 'Invalid IP address'];
        }

        // Reject metadata endpoint before broader link-local range.
        if (self::isMetadata($ip)) {
            return ['allowed' => false, 'reason' => 'Cloud metadata endpoint not allowed'];
        }

        // Reject dangerous IP ranges (always, regardless of allowlist)
        if (self::isLoopback($ip, $ipLong)) {
            return ['allowed' => false, 'reason' => 'Loopback IP addresses not allowed'];
        }

        if (self::isLinkLocal($ip, $ipLong)) {
            return ['allowed' => false, 'reason' => 'Link-local IP addresses not allowed'];
        }

        if (self::isMulticast($ip, $ipLong)) {
            return ['allowed' => false, 'reason' => 'Multicast IP addresses not allowed'];
        }

        if (self::isBroadcast($ip)) {
            return ['allowed' => false, 'reason' => 'Broadcast IP address not allowed'];
        }

        if (self::isUnspecified($ip, $ipLong)) {
            return ['allowed' => false, 'reason' => 'Unspecified IP address not allowed'];
        }

        // Get allowlist from config
        $cidrs = config('services.hikvision.allowed_cidrs', []);
        
        // Empty allowlist always denies real-device connection.
        if (empty($cidrs)) {
            Log::warning("No CIDR allowlist configured. Blocking IP {$ip}.");
            return ['allowed' => false, 'reason' => 'No CIDR allowlist configured'];
        }

        // Check if IP is in any allowlist CIDR
        foreach ($cidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return ['allowed' => true, 'reason' => "IP in allowlist CIDR: {$cidr}"];
            }
        }

        return ['allowed' => false, 'reason' => "IP {$ip} not in allowlist CIDRs: " . implode(', ', $cidrs)];
    }

    /**
     * Check if IP is within CIDR block (inclusive).
     * Supports both /32 (single IP) dan /24, /16, /8 ranges.
     */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            // Single IP (no CIDR notation)
            return $ip === $cidr;
        }

        [$subnet, $mask] = explode('/', $cidr);
        $subnetLong = ip2long($subnet);
        $ipLong = ip2long($ip);

        if ($subnetLong === false || $ipLong === false) {
            return false;
        }

        $mask = (int) $mask;
        if ($mask < 0 || $mask > 32) {
            return false;
        }

        $maskBits = -1 << (32 - $mask);
        $maskBits = $maskBits & 0xffffffff;

        return ($subnetLong & $maskBits) === ($ipLong & $maskBits);
    }

    private static function isLoopback(string $ip, int $ipLong): bool
    {
        // 127.0.0.0/8
        return $ipLong >= ip2long('127.0.0.0') && $ipLong <= ip2long('127.255.255.255');
    }

    private static function isLinkLocal(string $ip, int $ipLong): bool
    {
        // 169.254.0.0/16
        return $ipLong >= ip2long('169.254.0.0') && $ipLong <= ip2long('169.254.255.255');
    }

    private static function isMulticast(string $ip, int $ipLong): bool
    {
        // 224.0.0.0/4
        return $ipLong >= ip2long('224.0.0.0') && $ipLong <= ip2long('239.255.255.255');
    }

    private static function isBroadcast(string $ip): bool
    {
        return $ip === '255.255.255.255';
    }

    private static function isMetadata(string $ip): bool
    {
        // AWS/GCP metadata endpoint
        return $ip === '169.254.169.254';
    }

    private static function isUnspecified(string $ip, int $ipLong): bool
    {
        // 0.0.0.0 atau 0.0.0.0/8
        return $ipLong >= ip2long('0.0.0.0') && $ipLong <= ip2long('0.0.0.255');
    }
}
