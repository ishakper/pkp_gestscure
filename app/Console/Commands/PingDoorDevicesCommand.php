<?php

namespace App\Console\Commands;

use App\Models\Door;
use App\Services\HikvisionIsapiService;
use Illuminate\Console\Command;

class PingDoorDevicesCommand extends Command
{
    protected $signature = 'doors:ping';
    protected $description = 'Ping all physical access doors via ISAPI and update connection status automatically';

    public function handle(HikvisionIsapiService $isapiService): int
    {
        $this->info('Pinging physical access control terminals...');

        $doors = Door::all();
        foreach ($doors as $door) {
            if ($door->is_manual_override) {
                $this->line("Skipping {$door->door_id} ({$door->door_name}) - Manual override enabled ({$door->connection_status})");
                continue;
            }

            $isOnline = $isapiService->pingDevice($door);
            $newStatus = $isOnline ? 'online' : 'offline';

            $door->update([
                'connection_status' => $newStatus,
                'last_checked_at' => now(),
            ]);

            $this->info("Updated {$door->door_id} ({$door->device_ip}) -> Status: {$newStatus}");
        }

        $this->info('Door ping sweep completed.');
        return Command::SUCCESS;
    }
}
