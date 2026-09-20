<?php

namespace App\Domain\Hikvision;

/**
 * DeviceCapability represents a distinct hardware or protocol capability
 * with separate tracking of support, installation, configuration, and health states.
 *
 * Capability != Installation: A capability may be supported but not physically installed.
 */
class DeviceCapability
{
    public const STATE_UNKNOWN = 'unknown';
    public const STATE_SUPPORTED = 'supported';
    public const STATE_INSTALLED = 'installed';
    public const STATE_CONFIGURED = 'configured';
    public const STATE_HEALTHY = 'healthy';

    public const STATE_DEGRADED = 'degraded';
    public const STATE_OFFLINE = 'offline';
    public const STATE_NOT_SUPPORTED = 'not_supported';

    /**
     * Canonical capability name.
     */
    public string $name;

    /**
     * Is this capability supported by device firmware/hardware?
     */
    public string $supported = self::STATE_UNKNOWN; // unknown, yes, no

    /**
     * Is this capability physically installed? (e.g., door contact, exit button)
     */
    public string $installed = self::STATE_UNKNOWN; // unknown, yes, no

    /**
     * Is this capability configured/enabled on the device?
     */
    public string $configured = self::STATE_UNKNOWN; // unknown, yes, no

    /**
     * Is this capability currently operational/healthy?
     */
    public string $healthy = self::STATE_UNKNOWN; // unknown, yes, no, degraded

    /**
     * Optional: Last observation timestamp.
     */
    public ?\DateTime $observed_at = null;

    /**
     * Optional: Error or status message.
     */
    public ?string $status_message = null;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Mark capability as supported.
     */
    public function markSupported(bool $yes = true): self
    {
        $this->supported = $yes ? 'yes' : 'no';
        return $this;
    }

    /**
     * Mark capability as installed.
     */
    public function markInstalled(bool $yes = true): self
    {
        $this->installed = $yes ? 'yes' : 'no';
        return $this;
    }

    /**
     * Mark capability as configured.
     */
    public function markConfigured(bool $yes = true): self
    {
        $this->configured = $yes ? 'yes' : 'no';
        return $this;
    }

    /**
     * Mark capability as healthy.
     */
    public function markHealthy(string $state = 'yes'): self
    {
        $this->healthy = $state; // yes, no, degraded
        return $this;
    }

    /**
     * Is capability ready for production use?
     * (Supported, installed, configured, AND healthy)
     */
    public function isReady(): bool
    {
        return $this->supported === 'yes'
            && $this->installed === 'yes'
            && $this->configured === 'yes'
            && $this->healthy === 'yes';
    }

    /**
     * Serialize to JSON.
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'supported' => $this->supported,
            'installed' => $this->installed,
            'configured' => $this->configured,
            'healthy' => $this->healthy,
            'observed_at' => $this->observed_at?->toIso8601String(),
            'status_message' => $this->status_message,
        ];
    }
}
