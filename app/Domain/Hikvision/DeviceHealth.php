<?php

namespace App\Domain\Hikvision;

use Carbon\Carbon;

/**
 * DeviceHealth represents operational state of a Hikvision device.
 */
class DeviceHealth
{
    public const STATUS_ONLINE = 'online';
    public const STATUS_OFFLINE = 'offline';
    public const STATUS_DEGRADED = 'degraded';
    public const STATUS_UNKNOWN = 'unknown';

    /**
     * Device connectivity state.
     */
    public string $status = self::STATUS_UNKNOWN;

    /**
     * Last time device was reachable.
     */
    public ?Carbon $last_seen_at = null;

    /**
     * Round-trip latency in milliseconds.
     */
    public ?int $latency_ms = null;

    /**
     * Device model (e.g., DS-K1T804AMF).
     */
    public string $model = '';

    /**
     * Device serial number.
     */
    public string $serial = '';

    /**
     * Firmware version.
     */
    public string $firmware = '';

    /**
     * AlertStream connection status.
     */
    public string $stream_status = self::STATUS_UNKNOWN; // online, offline, degraded

    /**
     * Last event received timestamp.
     */
    public ?Carbon $last_event_at = null;

    /**
     * Total users on device.
     */
    public int $user_count = 0;

    /**
     * Total cards on device.
     */
    public int $card_count = 0;

    /**
     * Device clock offset in seconds (positive = ahead, negative = behind).
     */
    public int $clock_offset_seconds = 0;

    /**
     * NTP synchronization status.
     */
    public string $ntp_status = self::STATUS_UNKNOWN;

    /**
     * Device capability map.
     *
     * @var DeviceCapability[]
     */
    public array $capabilities = [];

    /**
     * Optional error/status message.
     */
    public ?string $error_message = null;

    public function __construct(
        string $model = '',
        string $serial = '',
        string $firmware = ''
    ) {
        $this->model = $model;
        $this->serial = $serial;
        $this->firmware = $firmware;
    }

    /**
     * Mark device as online.
     */
    public function markOnline(int $latencyMs = 0): self
    {
        $this->status = self::STATUS_ONLINE;
        $this->last_seen_at = Carbon::now();
        $this->latency_ms = $latencyMs;
        $this->error_message = null;
        return $this;
    }

    /**
     * Mark device as offline.
     */
    public function markOffline(string $reason = ''): self
    {
        $this->status = self::STATUS_OFFLINE;
        $this->error_message = $reason ?: 'Device unreachable';
        return $this;
    }

    /**
     * Mark device as degraded.
     */
    public function markDegraded(string $reason = ''): self
    {
        $this->status = self::STATUS_DEGRADED;
        $this->error_message = $reason;
        return $this;
    }

    /**
     * Classify NTP health based on clock offset.
     */
    public function classifyClockHealth(): string
    {
        $offset = abs($this->clock_offset_seconds);
        
        if ($offset < 1) {
            return 'healthy'; // < 1 second drift
        }
        
        if ($offset < 60) {
            return 'warning'; // < 1 minute drift
        }
        
        return 'critical'; // >= 1 minute drift
    }

    /**
     * Is device operationally ready?
     */
    public function isReady(): bool
    {
        return $this->status === self::STATUS_ONLINE;
    }

    /**
     * Serialize to JSON.
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'latency_ms' => $this->latency_ms,
            'model' => $this->model,
            'serial' => $this->serial,
            'firmware' => $this->firmware,
            'stream_status' => $this->stream_status,
            'last_event_at' => $this->last_event_at?->toIso8601String(),
            'user_count' => $this->user_count,
            'card_count' => $this->card_count,
            'clock_offset_seconds' => $this->clock_offset_seconds,
            'ntp_status' => $this->ntp_status,
            'clock_health' => $this->classifyClockHealth(),
            'capabilities' => array_map(fn($c) => $c->toArray(), $this->capabilities),
            'error_message' => $this->error_message,
        ];
    }
}
