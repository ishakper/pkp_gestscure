<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class HikvisionStreamLeaseManager
{
    public const DEFAULT_LEASE_TTL = 30; // 30 seconds

    /**
     * Cache key for the lease.
     */
    public static function key(string $doorId): string
    {
        return "hikvision-alertstream:lease:" . strtoupper($doorId);
    }

    /**
     * Attempt to acquire the single-consumer lease for a door.
     */
    public function acquire(string $doorId, string $ownerToken, int $ttlSeconds = self::DEFAULT_LEASE_TTL): bool
    {
        $key = self::key($doorId);
        $current = Cache::get($key);
        $now = time();

        if (is_array($current) && !empty($current['token'])) {
            if ($current['token'] === $ownerToken) {
                // Re-entrant or already owned
                $this->putLease($key, $ownerToken, $ttlSeconds);
                return true;
            }

            // Check if existing lease is stale
            $lastHeartbeat = (int) ($current['heartbeat'] ?? 0);
            if (($now - $lastHeartbeat) < $ttlSeconds) {
                // Active lease held by another consumer
                return false;
            }

            // Stale lease recovered
        }

        $this->putLease($key, $ownerToken, $ttlSeconds);
        return true;
    }

    /**
     * Renew lease heartbeat for the active owner.
     */
    public function renew(string $doorId, string $ownerToken, int $ttlSeconds = self::DEFAULT_LEASE_TTL): bool
    {
        $key = self::key($doorId);
        $current = Cache::get($key);

        if (is_array($current) && !empty($current['token'])) {
            if ($current['token'] !== $ownerToken) {
                return false;
            }
        }

        $this->putLease($key, $ownerToken, $ttlSeconds);
        return true;
    }

    /**
     * Check if a specific owner holds the lease.
     */
    public function isOwner(string $doorId, string $ownerToken, int $ttlSeconds = self::DEFAULT_LEASE_TTL): bool
    {
        $key = self::key($doorId);
        $current = Cache::get($key);
        if (!is_array($current) || empty($current['token'])) {
            return false;
        }

        if ($current['token'] !== $ownerToken) {
            return false;
        }

        return (time() - (int) ($current['heartbeat'] ?? 0)) <= $ttlSeconds;
    }

    /**
     * Release lease if held by owner.
     */
    public function release(string $doorId, string $ownerToken): bool
    {
        $key = self::key($doorId);
        $current = Cache::get($key);

        if (is_array($current) && !empty($current['token'])) {
            if ($current['token'] === $ownerToken) {
                Cache::forget($key);
                return true;
            }
            return false;
        }

        Cache::forget($key);
        return true;
    }

    /**
     * Get current lease data.
     */
    public function getLease(string $doorId): ?array
    {
        return Cache::get(self::key($doorId));
    }

    protected function putLease(string $key, string $ownerToken, int $ttlSeconds): void
    {
        Cache::put($key, [
            'token' => $ownerToken,
            'heartbeat' => time(),
            'ttl' => $ttlSeconds,
            'pid' => getmypid(),
        ], $ttlSeconds * 2);
    }
}
