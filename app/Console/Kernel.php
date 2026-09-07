<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Auto-ping doors every minute to update connection status
        $schedule->command('doors:ping')->everyMinute();

        // Retry failed door assignments every 5 minutes
        $schedule->call(function () {
            $failedAssignments = \App\Models\DoorAssignment::where('sync_status', 'failed')->get();
            foreach ($failedAssignments as $assignment) {
                \App\Jobs\SyncDoorAccessJob::dispatch($assignment->id);
            }
        })->everyFiveMinutes();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
