<?php

namespace App\Console\Commands;

use App\Models\Door;
use App\Services\PhysicalUserReconciliationService;
use Illuminate\Console\Command;

class ReconcileHikvisionUsersCommand extends Command
{
    protected $signature = 'door:reconcile-users
                            {door : The Door ID (e.g. DOOR-B)}
                            {--apply : Commit new employee records to database}
                            {--limit=200 : Maximum device users to inspect}';

    protected $description = 'Reconcile physical Hikvision device users with SecureGate employee directory';

    public function handle(PhysicalUserReconciliationService $service): int
    {
        $doorId = $this->argument('door');
        $door = Door::where('door_id', $doorId)->first();

        if (!$door) {
            $this->error("Door not found: {$doorId}");
            return Command::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = (int) $this->option('limit');

        $this->info("Starting reconciliation for {$door->name} ({$door->door_id})...");

        if (!$apply) {
            $this->warn("Running in DRY-RUN mode. Pass --apply to write changes.");
        }

        $result = $service->reconcile($door, $apply, $limit);

        if (!($result['success'] ?? false)) {
            $this->error("Reconciliation failed: " . ($result['error'] ?? 'Unknown error'));
            return Command::FAILURE;
        }

        $stats = $result['stats'] ?? [];
        $this->info("Reconciliation Complete:");
        $this->line("DEVICE_USERS: " . ($stats['device_users'] ?? 0));
        $this->line("UNIQUE_DEVICE_EMPLOYEE_NO: " . ($stats['unique_device_employee_no'] ?? 0));
        $this->line("MATCHED_EXISTING: " . ($stats['matched_existing'] ?? 0));
        $this->line("CREATED: " . ($stats['created'] ?? 0));
        $this->line("PENDING_CREATION: " . ($stats['pending_creation'] ?? 0));
        $this->line("CONFLICT: " . ($stats['conflict'] ?? 0));
        $this->line("UNKNOWN: " . ($stats['unknown'] ?? 0));
        $this->line("DUMMY_ONLY: " . ($stats['dummy_only'] ?? 0));
        $this->line("CARD_REGISTERED: " . ($stats['card_registered'] ?? 0));
        $this->line("NO_CARD: " . ($stats['no_card'] ?? 0));
        $this->line("DUPLICATE_EMPLOYEE_NO: " . ($stats['duplicate_employee_no'] ?? 0));

        $rows = array_map(function ($r) {
            return [
                $r['employee_id'],
                $r['name'],
                $r['device_name'] ?? '-',
                $r['card_registered'] ? 'YES' : 'NO',
                $r['action'],
                $r['status'],
            ];
        }, array_slice($result['records'] ?? [], 0, 30));

        $this->table(['Employee ID', 'Name', 'Device Name', 'Card Registered', 'Action', 'Status'], $rows);

        if (count($result['records'] ?? []) > 30) {
            $this->line("... and " . (count($result['records']) - 30) . " more records.");
        }

        return Command::SUCCESS;
    }
}
