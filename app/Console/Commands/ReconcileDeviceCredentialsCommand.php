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
        $value = fn ($v) => $v === null ? '-' : $v; // '-' = not reported / not verified, never 0
        $this->table(
            ['Door', 'Status', 'Users', 'Cards', 'FP', 'Device matched', 'Identity verified', 'Identity unverified', 'Identity review', 'Synced', 'Partial', 'Review', 'Device-only', 'App-only', 'Conflict', 'FP unknown', 'Error'],
            collect($run->summary['doors'] ?? [])->map(fn ($d) => [
                $d['door_code'], $d['status'], $value($d['users_on_device']), $value($d['cards_on_device']), $value($d['fingerprints_on_device']),
                $value($d['device_matched']), $value($d['identity_verified']), $value($d['identity_unverified']), $value($d['identity_review']),
                $value($d['synced']), $value($d['partial']), $value($d['review']), $value($d['device_only']), $value($d['app_only']),
                $value($d['conflicts']), $value($d['fingerprint_unknown']), $d['error'] ?? '',
            ])->all()
        );

        return $run->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
