<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\DoorAssignment;
use Illuminate\Console\Command;

class VerifyQueueSyncCommand extends Command
{
    protected $signature = 'doors:verify-sync-result';
    protected $description = 'Display the latest door assignment sync status and activity log entry';

    public function handle(): int
    {
        $this->info('--- LATEST DOOR ASSIGNMENT STATUS ---');
        $assignment = DoorAssignment::with(['employee', 'door'])->latest('updated_at')->first();

        if (!$assignment) {
            $this->warn('No door assignment found.');
            return Command::FAILURE;
        }

        $this->table(
            ['ID', 'Employee', 'Door Code', 'Sync Status', 'Attempts', 'Last Synced At', 'Last Sync Error'],
            [[
                $assignment->id,
                $assignment->employee ? "{$assignment->employee->name} ({$assignment->employee->employee_id})" : 'N/A',
                $assignment->door ? $assignment->door->door_id : 'N/A',
                $assignment->sync_status,
                $assignment->sync_attempts,
                $assignment->last_synced_at ? $assignment->last_synced_at->toDateTimeString() : 'None',
                $assignment->last_sync_error ?? 'None',
            ]]
        );

        $this->info("\n--- LATEST ACCESS LOGS (WEBHOOK TAP EVENTS) ---");
        $accessLogs = \App\Models\AccessLog::with(['door', 'employee'])->latest('id')->take(4)->get();
        $accessRows = [];
        foreach ($accessLogs as $al) {
            $accessRows[] = [
                $al->id,
                $al->log_id,
                $al->door ? $al->door->door_id : 'N/A',
                $al->employee ? "{$al->employee->name} (ID: {$al->employee->id})" : 'NULL (Unknown Card)',
                $al->verify_method,
                $al->access_status,
                $al->timestamp ? $al->timestamp->toDateTimeString() : 'N/A',
            ];
        }
        $this->table(
            ['ID', 'Log ID', 'Door Code', 'Employee', 'Method', 'Status', 'Timestamp'],
            $accessRows
        );

        $this->info("\n--- LATEST ACTIVITY LOG AUDIT TRAIL ---");
        $logs = ActivityLog::latest('id')->take(3)->get();
        
        $tableRows = [];
        foreach ($logs as $log) {
            $tableRows[] = [
                $log->id,
                $log->action,
                $log->subject_type ?? 'N/A',
                $log->subject_id ?? 'N/A',
                $log->description,
                $log->timestamp ? $log->timestamp->toDateTimeString() : $log->created_at->toDateTimeString(),
            ];
        }

        $this->table(
            ['Log ID', 'Action', 'Subject', 'Subject ID', 'Description', 'Timestamp'],
            $tableRows
        );

        return Command::SUCCESS;
    }
}
