<?php

namespace App\Services;

class IpCidrValidator
{
    /**
     * Validate an IPv4 address against the configured network allowlist and
     * optional exact addresses assigned to the door being tested.
     */
    public static function validateManualDeviceIp(string $ip, array $doorAddresses = []): array
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ['allowed' => false, 'reason' => 'Invalid IPv4 address format'];
        }

        if (self::inCidr($ip, '0.0.0.0/8')) {
            return ['allowed' => false, 'reason' => 'Unspecified IP addresses are not allowed'];
        }
        if (self::inCidr($ip, '127.0.0.0/8')) {
            return ['allowed' => false, 'reason' => 'Loopback IP addresses are not allowed'];
        }
        if (self::inCidr($ip, '169.254.0.0/16')) {
            return ['allowed' => false, 'reason' => 'Link-local and metadata IP addresses are not allowed'];
        }
        if (self::inCidr($ip, '224.0.0.0/4')) {
            return ['allowed' => false, 'reason' => 'Multicast IP addresses are not allowed'];
        }
        if ($ip === '255.255.255.255') {
            return ['allowed' => false, 'reason' => 'Broadcast IP addresses are not allowed'];
        }

        foreach ($doorAddresses as $doorAddress) {
            if (is_string($doorAddress) && trim($doorAddress) !== '' && hash_equals(trim($doorAddress), $ip)) {
                return ['allowed' => true, 'reason' => 'IP is assigned to this door'];
            }
        }

        foreach ((array) config('services.hikvision.allowed_cidrs', []) as $cidr) {
            if (is_string($cidr) && self::inCidr($ip, trim($cidr))) {
                return ['allowed' => true, 'reason' => 'IP is in an authorized device subnet'];
            }
        }

        return ['allowed' => false, 'reason' => 'IP is not authorized for this door'];
    }

    private static function inCidr(string $ip, string $cidr): bool
    {
        if ($cidr === '') {
            return false;
        }
        if (!str_contains($cidr, '/')) {
            return filter_var($cidr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && hash_equals($cidr, $ip);
        }

        [$subnet, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long((string) $subnet);
        if ($ipLong === false || $subnetLong === false || !ctype_digit((string) $prefix)) {
            return false;
        }

        $prefix = (int) $prefix;
        if ($prefix < 0 || $prefix > 32) {
            return false;
        }

        $mask = $prefix === 0 ? 0 : ((-1 << (32 - $prefix)) & 0xffffffff);
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
