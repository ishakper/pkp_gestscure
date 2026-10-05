<?php

namespace App\Console\Commands;

use App\Services\DeviceCredentialReconciliationService;
use Illuminate\Console\Command;

/**
 * Read-only device inventory + reconciliation. Only UserInfo/CardInfo searches are sent to
 * the devices; employees are never written. Results go to the reconciliation tables.
 */
class ReconcileDeviceCredentialsCommand extends Command
{
    protected $signature = 'device:reconcile {--door=* : Door database id(s); default all doors} {--limit=1000 : Maximum users to read per device}';

    protected $description = 'Read-only comparison of device persons/cards/fingerprints with app employees';

    public function handle(DeviceCredentialReconciliationService $service): int
    {
        $doorIds = array_map('intval', (array) $this->option('door'));
        $run = $service->run(null, $doorIds, max(1, (int) $this->option('limit')));

        $this->info("Run #{$run->id}: {$run->status} (read-only)");
        $this->table(
            ['Door', 'Status', 'Users', 'Cards', 'FP', 'Matched', 'Device-only', 'App-only', 'Conflict', 'Review', 'Error'],
            collect($run->summary['doors'] ?? [])->map(fn ($d) => [
                $d['door_code'], $d['status'], $d['users_on_device'] ?? '-', $d['cards_on_device'] ?? '-', $d['fingerprints_on_device'] ?? '-',
                $d['matched'] ?? '-', $d['device_only'] ?? '-', $d['app_only'] ?? '-', $d['conflicts'] ?? '-', $d['review'] ?? '-', $d['error'] ?? '',
            ])->all()
        );

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
