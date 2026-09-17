<?php

namespace App\Console\Commands;

use App\Models\Door;
use App\Services\HikvisionAlertStreamClient;
use App\Services\HikvisionEventIngestionService;
use App\Services\HikvisionStreamLeaseManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class StreamHikvisionAlertEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'door:stream-events
                            {door_id=DOOR-B : Kode identitas terminal pintu}
                            {--once : Jalankan satu siklus tanpa loop reconnect}
                            {--max-events= : Berhenti setelah menerima N event}
                            {--max-runtime= : Berhenti setelah N detik berjalan}
                            {--no-reconnect : Jangan mencoba reconnect jika koneksi putus}
                            {--allow-history : Izinkan penyerapan historical backlog (DEFAULT: LIVE_ONLY)}
                            {--timeout=0 : Timeout cURL per koneksi dalam detik (0 = indefinite)}';

    /**
     * The console command description.
     */
    protected $description = 'Buka koneksi outbound alertStream kontinu ke terminal Hikvision dan tangkap event akses secara real-time';

    protected const BACKOFF_DELAYS = [2, 4, 8, 16, 30, 60];

    /**
     * Execute the console command.
     */
    public function handle(
        HikvisionAlertStreamClient $streamClient,
        HikvisionEventIngestionService $ingestionService,
        HikvisionStreamLeaseManager $leaseManager
    ): int {
        $doorId = strtoupper((string) $this->argument('door_id'));
        $door = Door::where('door_id', $doorId)->orWhere('id', $doorId)->first();

        if (!$door) {
            $this->error("Pintu [{$doorId}] tidak ditemukan dalam sistem database.");
            return 1;
        }

        $ownerToken = (string) Str::uuid();

        // 1. Renewable Single-Consumer Lease Lock
        if (!$leaseManager->acquire($door->door_id, $ownerToken)) {
            $this->warn("Stream sudah aktif untuk pintu [{$door->door_id}]. Menolak duplikasi listener.");
            return 0;
        }

        // Secondary lock for backward compatibility
        $compatLock = Cache::lock("hikvision-alertstream:{$door->door_id}", 120);
        $compatLockAcquired = $compatLock->get();

        $once = (bool) $this->option('once');
        $noReconnect = (bool) $this->option('no-reconnect');
        $allowHistory = (bool) $this->option('allow-history');
        $ingestionMode = $allowHistory ? 'HISTORICAL_REPLAY' : 'LIVE_ONLY';
        $maxEvents = $this->option('max-events') ? (int) $this->option('max-events') : null;
        $maxRuntime = $this->option('max-runtime') ? (float) $this->option('max-runtime') : null;
        $timeout = (int) $this->option('timeout');

        $this->info("================================================================================");
        $this->info("  HIKVISION ISAPI - OUTBOUND ALERTSTREAM INGESTION DAEMON");
        $this->info("================================================================================");
        $this->info("Target Pintu   : {$door->door_id} ({$door->name})");
        $this->info("IP Terminal    : {$door->device_ip}");
        $this->info("Ingestion Mode : {$ingestionMode}" . ($allowHistory ? ' (HISTORICAL REPLAY ALLOWED)' : ' (LIVE ONLY - Backlog Filtered)'));
        $this->info("Status         : Membuka koneksi stream HTTP Digest multipart...");

        $streamStartedAt = now();
        $eventsReceived = 0;
        $eventsIngested = 0;
        $eventsSkippedBacklog = 0;
        $eventsDuplicates = 0;
        $eventsInvalid = 0;
        $reconnectAttempts = 0;
        $connectedAt = null;
        $lastHeartbeatAt = null;
        $lastEventAt = null;
        $shouldStop = false;
        $startTime = microtime(true);
        $lastLeaseRenew = time();

        // Signal handlers for graceful shutdown on POSIX systems
        if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGINT, function () use (&$shouldStop) {
                $shouldStop = true;
            });
            pcntl_signal(SIGTERM, function () use (&$shouldStop) {
                $shouldStop = true;
            });
        }

        $currentLastError = null;
        $currentLastError = null;
        $terminalStatus = null;
        $updateHealth = function (string $status, ?string $lastError = null) use (
            $door,
            $ingestionMode,
            &$eventsReceived,
            &$eventsIngested,
            &$eventsSkippedBacklog,
            &$eventsDuplicates,
            &$eventsInvalid,
            &$reconnectAttempts,
            &$connectedAt,
            &$lastHeartbeatAt,
            &$lastEventAt,
            &$currentLastError,
            &$terminalStatus
        ) {
            if ($lastError !== null) {
                $currentLastError = $lastError;
            }
            $terminalStatus = $status;

            Cache::put("hikvision-alertstream:health:{$door->door_id}", [
                'status' => $status,
                'door_id' => $door->door_id,
                'device_ip' => $door->device_ip,
                'ingestion_mode' => $ingestionMode,
                'connected_at' => $connectedAt,
                'last_heartbeat_at' => $lastHeartbeatAt,
                'last_event_at' => $lastEventAt,
                'events_received' => $eventsReceived,
                'events_ingested' => $eventsIngested,
                'events_skipped_backlog' => $eventsSkippedBacklog,
                'events_duplicates' => $eventsDuplicates,
                'events_invalid' => $eventsInvalid,
                'reconnect_attempts' => $reconnectAttempts,
                'last_error' => $currentLastError,
                'updated_at' => now()->toIso8601String(),
            ], 300);
        };

        try {
            while (!$shouldStop) {
                $updateHealth('CONNECTING');

                $streamOptions = [
                    'timeout' => $timeout,
                    'max_runtime' => $maxRuntime !== null ? max(0.1, $maxRuntime - (microtime(true) - $startTime)) : null,
                    'max_events' => $maxEvents !== null ? max(0, $maxEvents - $eventsReceived) : null,
                    'on_connected' => function () use ($updateHealth, &$connectedAt, &$lastHeartbeatAt) {
                        $connectedAt = now()->toIso8601String();
                        $lastHeartbeatAt = now()->toIso8601String();
                        $updateHealth('CONNECTED');
                    },
                    'on_tick' => function () use ($leaseManager, $door, $ownerToken, &$shouldStop, &$lastLeaseRenew, &$lastHeartbeatAt) {
                        $lastHeartbeatAt = now()->toIso8601String();
                        if ((time() - $lastLeaseRenew) >= 5) {
                            $lastLeaseRenew = time();
                            if (!$leaseManager->renew($door->door_id, $ownerToken)) {
                                $shouldStop = true;
                            }
                        }
                    },
                ];

                $streamResult = $streamClient->streamFromDoor(
                    $door,
                    function (array $eventData, Door $streamDoor) use (
                        $ingestionService,
                        $leaseManager,
                        $door,
                        $ownerToken,
                        $ingestionMode,
                        $streamStartedAt,
                        &$eventsReceived,
                        &$eventsIngested,
                        &$eventsSkippedBacklog,
                        &$eventsDuplicates,
                        &$eventsInvalid,
                        &$lastEventAt,
                        &$lastHeartbeatAt
                    ): bool {
                        $lastHeartbeatAt = now()->toIso8601String();
                        $eventsReceived++;
                        $lastEventAt = now()->toIso8601String();
                        $leaseManager->renew($door->door_id, $ownerToken);

                        $ingestResult = $ingestionService->ingest(
                            $eventData,
                            $streamDoor,
                            'HIKVISION_ALERTSTREAM',
                            [
                                'mode' => $ingestionMode,
                                'stream_started_at' => $streamStartedAt,
                            ]
                        );

                        $status = $ingestResult['status'] ?? 'unknown';

                        if ($status === 'skipped') {
                            if (($ingestResult['reason_code'] ?? '') === 'HISTORICAL_BACKLOG') {
                                $eventsSkippedBacklog++;
                            }
                            return true;
                        }

                        if ($status === 'invalid' || $status === 'ignored' || $status === 'error') {
                            $eventsInvalid++;
                            return true;
                        }

                        if (!empty($ingestResult['duplicate'])) {
                            $eventsDuplicates++;
                            return true;
                        }

                        if ($status === 'success') {
                            $eventsIngested++;
                            $logId = $ingestResult['data']['log_id'] ?? '-';
                            $accStatus = $ingestResult['data']['access_status'] ?? '-';

                            // Operational log without PII (no employee name, no card number)
                            $this->line(sprintf(
                                "[%s] EVENT #%d | %s | Status: %s | Log: %s",
                                now()->format('H:i:s'),
                                $eventsReceived,
                                $streamDoor->door_id,
                                $accStatus,
                                $logId
                            ));
                            return true;
                        }

                        return true;
                    },
                    function () use (&$shouldStop, $maxRuntime, $startTime, $maxEvents, &$eventsReceived) {
                        if ($shouldStop) {
                            return true;
                        }
                        if ($maxRuntime !== null && (microtime(true) - $startTime) >= $maxRuntime) {
                            return true;
                        }
                        if ($maxEvents !== null && $eventsReceived >= $maxEvents) {
                            return true;
                        }
                        return false;
                    },
                    $streamOptions
                );

                $lastHeartbeatAt = now()->toIso8601String();
                $httpCode = $streamResult['http_code'] ?? 0;

                // Non-retryable authentication failure: fail closed immediately
                if ($httpCode === 401 || $httpCode === 403) {
                    $this->error("Autentikasi terminal gagal (HTTP {$httpCode}). Kredensial tidak valid atau akses ditolak. Berhenti.");
                    $updateHealth('AUTH_FAILED', $streamResult['last_error'] ?? "HTTP Auth Error {$httpCode}");
                    return 2;
                }

                // Non-retryable unsupported endpoint
                if ($httpCode === 404 || $httpCode === 405) {
                    $this->error("Endpoint alertStream tidak didukung oleh perangkat terminal (HTTP {$httpCode}). Berhenti.");
                    $updateHealth('UNSUPPORTED', $streamResult['last_error'] ?? "HTTP Unsupported {$httpCode}");
                    return 3;
                }

                if ($streamResult['status'] === 'error') {
                    $this->error("Stream terputus dengan kesalahan: " . ($streamResult['last_error'] ?? 'Unknown error'));
                    $updateHealth('DISCONNECTED', $streamResult['last_error'] ?? 'Unknown error');
                } else {
                    $this->info("Koneksi stream berakhir ({$streamResult['status']}). Total event: {$eventsReceived}");
                    $updateHealth('DISCONNECTED');
                }

                // Reset backoff counter if stream delivered events
                if ($eventsReceived > 0) {
                    $reconnectAttempts = 0;
                }

                // Check termination conditions
                if ($once || $noReconnect || $shouldStop) {
                    break;
                }
                if ($maxEvents !== null && $eventsReceived >= $maxEvents) {
                    $this->info("Batas maksimum event tercapai ({$maxEvents}). Berhenti.");
                    break;
                }
                if ($maxRuntime !== null && (microtime(true) - $startTime) >= $maxRuntime) {
                    $this->info("Batas maksimum waktu berjalan tercapai ({$maxRuntime}s). Berhenti.");
                    break;
                }

                $reconnectAttempts++;
                $delayIdx = min($reconnectAttempts - 1, count(self::BACKOFF_DELAYS) - 1);
                $delay = self::BACKOFF_DELAYS[$delayIdx];

                $this->warn("Mencoba reconnect ke {$door->door_id} dalam {$delay} detik (percobaan ke-{$reconnectAttempts})...");
                $updateHealth('RECONNECTING', "Reconnecting in {$delay}s");

                // Sleep with responsiveness to shouldStop
                $sleepEnd = microtime(true) + $delay;
                while (microtime(true) < $sleepEnd && !$shouldStop) {
                    usleep(100000); // 100ms
                }
            }
        } finally {
            if (!in_array($terminalStatus, ['AUTH_FAILED', 'UNSUPPORTED'], true)) {
                $updateHealth('STOPPED');
            }
            $leaseManager->release($door->door_id, $ownerToken);
            if ($compatLockAcquired) {
                try {
                    $compatLock->release();
                } catch (\Throwable) {
                    // Ignore
                }
            }
            $this->info("Statistik Ingestion: Received={$eventsReceived}, Ingested={$eventsIngested}, SkippedBacklog={$eventsSkippedBacklog}, Duplicates={$eventsDuplicates}, Invalid={$eventsInvalid}");
            $this->info("Daemon alertStream untuk [{$door->door_id}] telah dimatikan secara aman.");
        }

        return 0;
    }
}
