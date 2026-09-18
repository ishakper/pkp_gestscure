<?php

namespace App\Services;

use App\Domain\Hikvision\DeviceCapability;
use App\Domain\Hikvision\DeviceHealth;
use App\Models\Door;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DeviceHealthService queries Hikvision devices and builds factual health state.
 */
class DeviceHealthService
{
    public function __construct(
        private HikvisionIsapiService $isapi
    ) {}

    /**
     * Query device and return complete health state.
     *
     * READ-ONLY: No device modifications.
     */
    public function getHealthForDoor(Door $door): DeviceHealth
    {
        $health = new DeviceHealth(
            $door->model ?? 'UNKNOWN',
            $door->serial_number ?? 'UNKNOWN',
            $door->firmware_version ?? 'UNKNOWN'
        );

        // Try to reach device
        try {
            $start = microtime(true);
            $status = $this->isapi->getDeviceStatus($door);
            $latency = (int) ((microtime(true) - $start) * 1000);

            $health->markOnline($latency);

            // Update from device response
            if (isset($status['model'])) {
                $health->model = $status['model'];
            }
            if (isset($status['serial'])) {
                $health->serial = $status['serial'];
            }
            if (isset($status['firmware'])) {
                $health->firmware = $status['firmware'];
            }

            // Extract counts
            if (isset($status['user_count'])) {
                $health->user_count = (int) $status['user_count'];
            }
            if (isset($status['card_count'])) {
                $health->card_count = (int) $status['card_count'];
            }

            // Device capabilities
            $this->populateCapabilities($health, $status);

            // Update door model for caching
            $door->update([
                'health_status' => 'online',
                'last_checked_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Device health check failed', [
                'door_id' => $door->id,
                'error' => $e->getMessage(),
            ]);
            $health->markOffline($e->getMessage());
            $door->update([
                'health_status' => 'offline',
                'last_checked_at' => Carbon::now(),
            ]);
        }

        return $health;
    }

    /**
     * Populate capability matrix from device status.
     */
    private function populateCapabilities(DeviceHealth $health, array $status): void
    {
        // ISAPI support
        $isapi = new DeviceCapability('ISAPI');
        $isapi->markSupported(true);
        if (isset($status['isapi_enabled'])) {
            $isapi->markConfigured($status['isapi_enabled']);
        }
        $health->capabilities['ISAPI'] = $isapi;

        // AlertStream support
        $stream = new DeviceCapability('AlertStream');
        $stream->markSupported(true);
        if (isset($status['stream_connected'])) {
            $stream->markConfigured(true);
            $health->stream_status = $status['stream_connected'] ? 'online' : 'offline';
        }
        if (isset($status['last_event'])) {
            $health->last_event_at = Carbon::parse($status['last_event']);
        }
        $health->capabilities['AlertStream'] = $stream;

        // Card support (always supported on this model)
        $card = new DeviceCapability('Card');
        $card->markSupported(true);
        $card->markConfigured(true);
        $health->capabilities['Card'] = $card;

        // Fingerprint support (query enrollment status)
        $fingerprint = new DeviceCapability('Fingerprint');
        $fingerprint->markSupported(true); // DS-K1T804AMF supports biometric
        if (isset($status['fingerprint_enabled'])) {
            $fingerprint->markConfigured($status['fingerprint_enabled']);
        }
        $health->capabilities['Fingerprint'] = $fingerprint;

        // Door Contact (supported, installation unknown without physical evidence)
        $doorContact = new DeviceCapability('Door Contact');
        $doorContact->markSupported(true);
        if (isset($status['door_contact_installed'])) {
            $doorContact->markInstalled($status['door_contact_installed']);
        } else {
            $doorContact->installed = 'unknown'; // Unknown without physical inspection
        }
        $health->capabilities['Door Contact'] = $doorContact;

        // Exit Button (supported, installation unknown)
        $exitButton = new DeviceCapability('Exit Button');
        $exitButton->markSupported(true);
        if (isset($status['exit_button_installed'])) {
            $exitButton->markInstalled($status['exit_button_installed']);
        } else {
            $exitButton->installed = 'unknown';
        }
        $health->capabilities['Exit Button'] = $exitButton;

        // Alarm Input/Output
        $alarmIO = new DeviceCapability('Alarm I/O');
        $alarmIO->markSupported(true);
        if (isset($status['alarm_io_installed'])) {
            $alarmIO->markInstalled($status['alarm_io_installed']);
        } else {
            $alarmIO->installed = 'unknown';
        }
        $health->capabilities['Alarm I/O'] = $alarmIO;

        // RS-485 (for secure control units)
        $rs485 = new DeviceCapability('RS-485');
        $rs485->markSupported(true);
        if (isset($status['rs485_enabled'])) {
            $rs485->markConfigured($status['rs485_enabled']);
        }
        $health->capabilities['RS-485'] = $rs485;

        // Wiegand reader
        $wiegand = new DeviceCapability('Wiegand');
        $wiegand->markSupported(true);
        if (isset($status['wiegand_installed'])) {
            $wiegand->markInstalled($status['wiegand_installed']);
        } else {
            $wiegand->installed = 'unknown';
        }
        $health->capabilities['Wiegand'] = $wiegand;

        // NTP/Time Sync
        $ntp = new DeviceCapability('NTP');
        $ntp->markSupported(true);
        if (isset($status['ntp_enabled'])) {
            $ntp->markConfigured($status['ntp_enabled']);
        }
        if (isset($status['clock_offset_seconds'])) {
            $health->clock_offset_seconds = (int) $status['clock_offset_seconds'];
            $health->ntp_status = $health->classifyClockHealth();
            $ntp->markHealthy($health->ntp_status);
        }
        $health->capabilities['NTP'] = $ntp;
    }

    /**
     * Get all devices health.
     */
    public function getAllDevicesHealth(): array
    {
        $doors = Door::all();
        $results = [];

        foreach ($doors as $door) {
            $results[$door->id] = $this->getHealthForDoor($door);
        }

        return $results;
    }
}
