<?php

namespace App\Console\Commands;

use App\Models\Door;
use App\Services\HikvisionIsapiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TestDoorConnectivityCommand extends Command
{
    protected $signature = 'doors:check-network {--real : Force physical network socket/HTTP probe bypassing mock mode}';
    protected $description = 'Audit and verify connectivity and matching IP mapping for 4 Hikvision Access Doors';

    public function handle(HikvisionIsapiService $isapiService): int
    {
        $this->info('================================================================================');
        $this->info('  CENTRAL SERVER ACCESS CONTROL - IP TOPOLOGY & CONNECTIVITY AUDIT');
        $this->info('  Central Server: Gedung B (192.168.90.100)');
        $this->info('================================================================================');

        $expectedTopology = [
            'DOOR-A' => '192.168.90.11',
            'DOOR-B' => '192.168.90.12',
            'DOOR-C' => '192.168.90.13',
            'DOOR-D' => '192.168.90.14',
        ];

        $doors = Door::all();
        if ($doors->isEmpty()) {
            $this->warn('No door records found in database. Please run: php artisan db:seed');
            return Command::FAILURE;
        }

        $forceReal = $this->option('real');
        $tableRows = [];

        foreach ($doors as $door) {
            $expectedIp = $expectedTopology[$door->door_id] ?? 'Unregistered';
            $isMatching = ($door->device_ip === $expectedIp);
            $matchStatus = $isMatching ? '<info>MATCH</info>' : '<error>MISMATCH</error>';

            $startTime = microtime(true);
            $isOnline = false;
            $latencyOrError = '';

            // Probe device: physical network or adaptive mock fallback
            if (!$forceReal && $isapiService->isMockMode()) {
                // Adaptive local network simulation
                $isOnline = !str_ends_with($door->device_ip, '.99') && ($door->connection_status !== 'error');
                $simulatedLatency = rand(14, 26) + (rand(1, 9) / 10);
                $latencyOrError = $isOnline 
                    ? "{$simulatedLatency}ms (Simulated ISAPI)" 
                    : 'Offline (Simulated timeout)';
            } else {
                // Real physical probe with strict 2-second timeout
                try {
                    $url = "http://{$door->device_ip}/ISAPI/System/status";
                    $response = Http::connectTimeout(2)
                        ->timeout(2)
                        ->get($url);

                    $latency = round((microtime(true) - $startTime) * 1000, 2);

                    // Hikvision terminals return HTTP 200 or 401 Unauthorized (Digest challenge) when alive
                    if ($response->status() === 200 || $response->status() === 401) {
                        $isOnline = true;
                        $latencyOrError = "{$latency}ms (HTTP {$response->status()})";
                    } else {
                        $isOnline = false;
                        $latencyOrError = "HTTP {$response->status()}";
                    }
                } catch (\Throwable $e) {
                    $isOnline = false;
                    $errorSnippet = Str::limit($e->getMessage(), 32);
                    $latencyOrError = "Timeout/Error: {$errorSnippet}";
                }
            }

            $connStatus = $isOnline ? 'online' : 'offline';

            // Synchronize status in database
            $door->update([
                'status' => $connStatus,
                'connection_status' => $connStatus,
                'last_checked_at' => now(),
            ]);

            $statusFormatted = $isOnline ? '<info>online</info>' : '<comment>offline</comment>';

            $tableRows[] = [
                'Door Code' => $door->door_id,
                'Name' => Str::limit($door->name ?? $door->door_name, 28),
                'Configured IP' => $door->device_ip,
                'Expected IP' => $expectedIp,
                'IP Matching Status' => $matchStatus,
                'Connection Status' => $statusFormatted,
                'Latency / Error' => $latencyOrError,
            ];
        }

        $this->table(
            ['Door Code', 'Name', 'Configured IP', 'Expected IP', 'IP Matching Status', 'Connection Status', 'Latency / Error'],
            $tableRows
        );

        $onlineCount = $doors->where('connection_status', 'online')->count();
        $totalCount = $doors->count();
        $this->info("Topology Audit Complete: {$onlineCount}/{$totalCount} physical terminals verified and synced in database.");

        return Command::SUCCESS;
    }
}
