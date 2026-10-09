<?php

namespace App\Console\Commands;

use App\Models\AccessLog;
use App\Models\Door;
use App\Models\Employee;
use App\Services\HikvisionIsapiService;
use Illuminate\Console\Command;

class InventoryHikvisionDoorCommand extends Command
{
    protected $signature = 'door:inventory {door_id} {--real : Read metadata from physical device} {--limit=100 : Maximum users or recent events to inspect}';
    protected $description = 'Read-only Hikvision inventory and SecureGate mapping dry-run';

    public function handle(HikvisionIsapiService $isapi): int
    {
        $doorId = strtoupper((string) $this->argument('door_id'));
        $door = Door::where('door_id', $doorId)->first();
        if (!$door) {
            $this->error("Door [{$doorId}] not found.");
            return self::FAILURE;
        }
        if (!$this->option('real') || $isapi->isMockMode()) {
            $this->error('Physical inventory requires --real and real ISAPI mode.');
            return self::FAILURE;
        }

        $before = [
            Employee::query()->orderBy('id')->get()->toArray(),
            $door->fresh()->toArray(),
            $door->doorAssignments()->orderBy('id')->get()->toArray(),
            AccessLog::query()->count(),
        ];
        $limit = max(1, min((int) $this->option('limit'), 1000));
        $result = $isapi->fetchUsers($door, $limit);
        $eventMode = !$result['status'] && ($result['unsupported'] ?? false);
        $accessLogMode = false;
        if ($eventMode) {
            $this->warn('USER DIRECTORY: UNSUPPORTED');
            $result = $isapi->fetchEvents($limit, $door);
            $accessLogMode = !$result['status'] && ($result['unsupported'] ?? false);
            if ($accessLogMode) {
                $this->warn('DEVICE EVENT SEARCH: UNSUPPORTED');
                $this->line('MODE: ACCESSLOG-DERIVED INVENTORY');
                $this->line('NOTE: This is NOT a full device user directory.');
                $this->line('Only identities observed through events already received by SecureGate are included.');
                $result = $this->fetchAccessLogEvents($door, $limit);
            } else {
                $this->line('MODE: EVENT-DERIVED INVENTORY');
                $this->line('NOTE: This is NOT a full device user directory.');
                $this->line('Only identities observed in recent access events are included.');
            }
        }
        if (!$result['status']) {
            $this->error($result['error'] ?? 'Inventory failed.');
            return self::FAILURE;
        }

        $employees = Employee::query()->get(['id', 'employee_id', 'hikvision_employee_no', 'nik', 'name']);
        $items = $eventMode ? $this->uniqueEventIdentities($result['events']) : $result['users'];
        $mappedIds = [];
        $counts = ['matched' => 0, 'new' => 0, 'conflicts' => 0, 'skipped' => 0];
        $rows = [];

        foreach ($items as $item) {
            $externalId = trim((string) ($item['employee_no'] ?? ''));
            if ($externalId === '') {
                $counts['skipped']++;
                $rows[] = $eventMode
                    ? ['-', $item['name'] ?: '-', $item['verify_method'] ?: '-', $item['access_status'] ?: '-', $item['time'] ?: '-', $item['door_name'] ?: ($item['door_no'] ?: '-'), 'SKIPPED: UNKNOWN EVENT IDENTITY']
                    : ['-', $item['name'] ?: '-', $this->cardSummary($item), $item['status'] ?: '-', 'SKIPPED: missing employee number'];
                continue;
            }

            $matches = $employees->filter(fn (Employee $employee) => in_array($externalId, array_filter([
                $employee->hikvision_employee_no,
                $employee->employee_id,
                $employee->nik,
            ]), true));
            if ($matches->count() > 1) {
                $counts['conflicts']++;
                $mappedIds = array_merge($mappedIds, $matches->pluck('id')->all());
                $mapping = 'CONFLICT';
            } elseif ($matches->count() === 1) {
                $counts['matched']++;
                $employee = $matches->first();
                $mappedIds[] = $employee->id;
                $mapping = "MATCH: {$employee->employee_id}";
            } else {
                $counts['new']++;
                $mapping = 'NEW CANDIDATE';
            }

            $rows[] = $eventMode
                ? [$externalId, $item['name'] ?: '-', $item['verify_method'] ?: '-', $item['access_status'] ?: '-', $item['time'] ?: '-', $item['door_name'] ?: ($item['door_no'] ?: '-'), $mapping]
                : [$externalId, $item['name'] ?: '-', $this->cardSummary($item), $item['status'] ?: '-', $mapping];
        }

        $dummyOnly = $employees->whereNotIn('id', array_unique($mappedIds))->count();
        $this->table($eventMode
            ? ['Employee/Person No', 'Display Name', 'Verify Method', 'Access Status', 'Event Timestamp', 'Door', 'SecureGate Mapping']
            : ['Employee/Person No', 'Display Name', 'Cards', 'Status', 'SecureGate Mapping'], $rows);
        if ($eventMode) {
            $this->table(['Events inspected', 'Unique identities observed', 'Matched employees', 'New candidates', 'Conflicts', 'Skipped/unknown identities', 'Dummy-only SecureGate employees'], [[
                $result['total'], count($items), $counts['matched'], $counts['new'], $counts['conflicts'], $counts['skipped'], $dummyOnly,
            ]]);
            if (($result['total_device_matches'] ?? $result['total']) > $result['total'] || $result['total'] >= $limit) {
                $this->warn('Event query partial/limited: increase --limit to inspect more recent events.');
            }
        } else {
            $this->table(['Total device matches', 'Inspected users', 'Matched employees', 'New candidates', 'Dummy-only employees', 'Conflicts', 'Skipped'], [[
                $result['total_device_matches'], $result['inspected_users'], $counts['matched'], $counts['new'], $dummyOnly, $counts['conflicts'], $counts['skipped'],
            ]]);
            if ($result['total_device_matches'] > $result['inspected_users']) {
                $this->warn('Inventory partial: increase --limit to inspect more device users.');
            }
        }

        if ($before !== [
            Employee::query()->orderBy('id')->get()->toArray(),
            $door->fresh()->toArray(),
            $door->doorAssignments()->orderBy('id')->get()->toArray(),
            AccessLog::query()->count(),
        ]) {
            $this->error('Read-only invariant failed: database records changed.');
            return self::FAILURE;
        }

        $this->info('DRY RUN ONLY: no device or database records changed.');
        return self::SUCCESS;
    }

    private function fetchAccessLogEvents(Door $door, int $limit): array
    {
        $logs = AccessLog::query()
            ->with('employee:id,employee_id,hikvision_employee_no,nik,name')
            ->where('door_id', $door->id)
            ->latest('timestamp')
            ->limit($limit)
            ->get();

        return [
            'status' => true,
            'total' => $logs->count(),
            'total_device_matches' => AccessLog::where('door_id', $door->id)->count(),
            'events' => $logs->map(static function (AccessLog $log) use ($door): array {
                $employee = $log->employee;
                return [
                    'employee_no' => (string) ($employee?->hikvision_employee_no ?: $employee?->employee_id ?: $log->nik ?: ''),
                    'name' => (string) ($employee?->name ?? ''),
                    'verify_method' => (string) ($log->verify_method ?? ''),
                    'access_status' => (string) ($log->access_status ?? ''),
                    'time' => $log->timestamp?->toIso8601String() ?? '',
                    'door_no' => (string) $door->door_id,
                    'door_name' => (string) $door->door_name,
                ];
            })->all(),
            'error' => null,
        ];
    }

    private function uniqueEventIdentities(array $events): array
    {
        $identities = [];
        $unknown = 0;
        foreach ($events as $event) {
            $externalId = trim((string) ($event['employee_no'] ?? ''));
            $key = $externalId !== '' ? "id:{$externalId}" : 'unknown:' . $unknown++;
            $identities[$key] ??= [
                'employee_no' => $externalId,
                'name' => (string) ($event['name'] ?? ''),
                'verify_method' => (string) ($event['verify_method'] ?? ''),
                'access_status' => (string) ($event['access_status'] ?? ''),
                'time' => (string) ($event['time'] ?? ''),
                'door_no' => (string) ($event['door_no'] ?? ''),
                'door_name' => (string) ($event['door_name'] ?? ''),
            ];
        }

        return array_values($identities);
    }

    private function cardSummary(array $user): string
    {
        return $user['card_count'] === null ? 'Not exposed' : (string) $user['card_count'];
    }
}
