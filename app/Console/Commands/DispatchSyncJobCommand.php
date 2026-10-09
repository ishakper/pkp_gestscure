<?php

namespace App\Console\Commands;

use App\Jobs\SyncDoorAccessJob;
use App\Models\Door;
use App\Models\DoorAssignment;
use App\Models\Employee;
use Illuminate\Console\Command;

class DispatchSyncJobCommand extends Command
{
    protected $signature = 'doors:dispatch-sync {--employee= : Employee ID} {--door= : Door ID}';
    protected $description = 'Queue a test SyncDoorAccessJob for an employee and door';

    public function handle(): int
    {
        $employee = $this->option('employee') 
            ? Employee::find($this->option('employee'))
            : Employee::first();

        $door = $this->option('door')
            ? Door::find($this->option('door'))
            : Door::first();

        if (!$employee || !$door) {
            $this->error('Employee or Door not found in database.');
            return Command::FAILURE;
        }

        $assignment = DoorAssignment::updateOrCreate(
            ['employee_id' => $employee->id, 'door_id' => $door->id],
            [
                'sync_status' => 'pending',
                'last_sync_error' => null,
            ]
        );

        $this->info("Prepared DoorAssignment #{$assignment->id}: Employee '{$employee->name}' ({$employee->employee_id}) <-> Door '{$door->name}' ({$door->door_id})");
        $this->info("Current sync_status: {$assignment->sync_status}");

        SyncDoorAccessJob::dispatch($employee->id, $door->id);

        $this->info("Successfully dispatched SyncDoorAccessJob for Employee #{$employee->id} and Door #{$door->id} to the queue.");
        $this->comment("Run 'php artisan queue:work --once --tries=1' to process.");

        return Command::SUCCESS;
    }
}
